<?php


namespace ccxt\async;

use ccxt;

// rounding mode
const TRUNCATE = ccxt\TRUNCATE;
const ROUND = ccxt\ROUND;
const ROUND_UP = ccxt\ROUND_UP;
const ROUND_DOWN = ccxt\ROUND_DOWN;

// digits counting mode
const DECIMAL_PLACES = ccxt\DECIMAL_PLACES;
const SIGNIFICANT_DIGITS = ccxt\SIGNIFICANT_DIGITS;
const TICK_SIZE = ccxt\TICK_SIZE;

// padding mode
const NO_PADDING = ccxt\NO_PADDING;
const PAD_WITH_ZERO = ccxt\PAD_WITH_ZERO;

use React;
use Recoil;

use Exception;

include 'throttle.php';

class Exchange extends \ccxt\Exchange {
    public static $loop;
    public static $kernel;
    public $client;
    public $marketsLoading = null;
    public $reloadingMarkets = null;
    public $async_api;
    public $tokenBucket;
    public $throttle;

    public static function get_loop() {
        if (!static::$loop) {
            static::$loop = React\EventLoop\Factory::create();
        }
        return static::$loop;
    }

    public static function get_kernel() {
        if (!static::$kernel) {
            static::$kernel = Recoil\React\ReactKernel::create(static::get_loop());
        }
        return static::$kernel;
    }

    public function __construct($options = array()) {
        $config = $this->omit($options, array('loop', 'kernel'));
        parent::__construct($config);
        // we only want one instance of the loop and one instance of the kernel
        if (static::$loop === null) {
            if (array_key_exists('loop', $options)) {
                static::$loop = $options['loop'];
            } else {
                static::$loop = React\EventLoop\Factory::create();
            }
        } else if (array_key_exists('loop', $options)) {
            throw new Exception($this->id, ' cannot use two different loops');
        }

        if (static::$kernel === null) {
            if (array_key_exists('kernel', $options)) {
                static::$kernel = $options['kernel'];
            } else {
                static::$kernel = Recoil\React\ReactKernel::create(static::$loop);
            }
        } else if (array_key_exists('kernel', $options)) {
            throw new Exception($this->id, ' cannot use two different loops');
        }

        $connector = new React\Socket\Connector(static::$loop, array(
            'timeout' => $this->timeout,
        ));
        if ($this->client === null) {
            $this->client = new React\Http\Browser(static::$loop, $connector);
            $this->client = $this->client->withRejectErrorResponse(false);
        }

        $class_methods = get_class_methods($this);
        $end_part = '_generator';
        $end_length = strlen($end_part);
        foreach ($class_methods as $method) {
            if (substr($method, -$end_length) === $end_part) {
                $async_method = substr($method, 0, strlen($method) - $end_length);
                $this->async_api[$async_method] = $method;
            }
        }
        $this->throttle = throttle($this->tokenBucket, static::$loop);
    }

    public function fetch($url, $method = 'GET', $headers = null, $body = null) {
        // returns a react promise
        $headers = $headers ? $headers : array();
        if ($this->verbose) {
            print_r(array('Request:', $method, $url, $headers, $body));
        }
        try {
            $result = yield $this->client->request($method, $url, $headers, $body);
        } catch (Exception $e) {
            $message = $e->getMessage();
            if (strpos($message, 'DNS query') !== false) {
                throw new ccxt\NetworkError($message);
            } else {
                throw new ccxt\ExchangeError($message);
            }
        }

        $response_body = strval($result->getBody());
        $raw_response_headers = $result->getHeaders();
        $raw_header_keys = array_keys($raw_response_headers);
        $response_headers = array();
        foreach ($raw_header_keys as $header) {
            $response_headers[$header] = $result->getHeaderLine($header);
        }
        $http_status_code = $result->getStatusCode();
        $http_status_text = $result->getReasonPhrase();

        if ($this->verbose) {
            print_r(array('Response:', $method, $url, $http_status_code, $response_headers, $response_body));
        }
        $json_response = $this->parse_json($response_body);
        $this->handle_errors($http_status_code, $http_status_text, $url, $method, $response_headers, $response_body, $json_response, $headers, $body);
        $this->handle_http_status_code($http_status_code, $http_status_text, $url, $method, $response_body);
        return $json_response ? $json_response : $response_body;
    }

    public function fetch2($path, $api = 'public', $method = 'GET', $params = array(), $headers = null, $body = null) {
        if ($this->enableRateLimit) {
            yield call_user_func($this->throttle, $this->rateLimit);
        }
        $request = $this->sign($path, $api, $method, $params, $headers, $body);
        return yield $this->fetch($request['url'], $request['method'], $request['headers'], $request['body']);
    }

    public function load_markets_helper($reload = false, $params = array()) {
        // copied from js
        if (!$reload && $this->markets) {
            if (!$this->markets_by_id) {
                return $this->set_markets ($this->markets);
            }
            return $this->markets;
        }
        $currencies = null;
        if ($this->has['fetchCurrencies']) {
            $currencies = yield $this->fetch_currencies ();
        }
        $markets = yield $this->fetch_markets ($params);
        return $this->set_markets ($markets, $currencies);
    }

    public function load_markets($reload = false, $params = array()) {
        if (($reload && !$this->reloadingMarkets) || !$this->marketsLoading) {
            $this->reloadingMarkets = true;
            $this->marketsLoading = static::$kernel->execute($this->load_markets_helper($reload, $params))->promise()->then(function ($resolved) {
                $this->reloadingMarkets = false;
                return $resolved;
            }, function ($error) {
                $this->reloadingMarkets = false;
                throw $error;
            });
        }
        return $this->marketsLoading;
    }

    // these methods are already defined in the base class so __call doesn't see them
    // we need to override them
    public function fetch_markets($params = array()) {
        return static::$kernel->execute($this->fetch_markets_generator($params))->promise();
    }

    public function fetch_order_book($symbol, $limit = null, $params = array()) {
        return static::$kernel->execute($this->fetch_order_book_generator($symbol, $limit, $params))->promise();
    }

    public function fetch_balance($params = array()) {
        return static::$kernel->execute($this->fetch_balance_generator($params))->promise();
    }

    public function fetch_trades($symbol, $since = null, $limit = null, $params = array()) {
        return static::$kernel->execute($this->fetch_trades_generator($symbol, $since, $limit, $params))->promise();
    }

    public function fetch_my_trades($symbol = null, $since = null, $limit = null, $params = array()) {
        return static::$kernel->execute($this->fetch_my_trades_generator($symbol, $since, $limit, $params))->promise();
    }

    public function fetch_open_orders($symbol = null, $since = null, $limit = null, $params = array()) {
        return static::$kernel->execute($this->fetch_open_orders_generator($symbol, $since, $limit, $params))->promise();
    }

    public function fetch_closed_orders($symbol = null, $since = null, $limit = null, $params = array()) {
        return static::$kernel->execute($this->fetch_closed_orders_generator($symbol, $since, $limit, $params))->promise();
    }

    public function fetch_orders($symbol = null, $since = null, $limit = null, $params = array()) {
        return static::$kernel->execute($this->fetch_orders_generator($symbol, $since, $limit, $params))->promise();
    }

    public function fetch_ticker($symbol, $params = array()) {
        return static::$kernel->execute($this->fetch_ticker_generator($symbol, $params))->promise();
    }

    public function fetch_tickers($symbols = array(), $params = array()) {
        return static::$kernel->execute($this->fetch_tickers_generator($symbols, $params))->promise();
    }

    public function create_order($symbol, $type, $side, $amount, $price = null, $params = array()) {
        return static::$kernel->execute($this->create_order_generator($symbol, $type, $side, $amount, $price, $params))->promise();
    }

    public function fetch_deposits($code = null, $since = null, $limit = null, $params = array()) {
        return static::$kernel->execute($this->fetch_deposits_generator($code, $since, $limit, $params))->promise();
    }

    public function fetch_withdrawals($code = null, $since = null, $limit = null, $params = array()) {
        return static::$kernel->execute($this->fetch_withdrawals_generator($code, $since, $limit, $params))->promise();
    }

    public function fetch_transactions($code = null, $since = null, $limit = null, $params = array()) {
        return static::$kernel->execute($this->fetch_transactions_generator($code, $since, $limit, $params))->promise();
    }

    public function fetch_deposit_address($code, $params = array()) {
        return static::$kernel->execute($this->fetch_deposit_address_generator($code, $params))->promise();
    }
}
