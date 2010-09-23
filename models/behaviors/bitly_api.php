<?php
/**
 * Copyright 2009-2010, Cake Development Corporation (http://cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2009-2010, Cake Development Corporation (http://cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

/**
 * Bit.ly Api access based off of the Twitter Api behavior
 *
 * @see http://code.google.com/p/bitly-api/wiki/ApiDocumentation
 */
App::import('Core', array('HttpSocket', 'Xml'));

/**
 * Bit.ly API Behavior
 *
 * @package bitly
 * @subpackage bitly.models.behaviors
 */
class BitlyApiBehavior extends ModelBehavior {
	
/**
 * Allows the mapping of preg-compatible regular expressions to public or
 * private methods in this class, where the array key is a /-delimited regular
 * expression, and the value is a class method.  Similar to the functionality of
 * the findBy* / findAllBy* magic methods.
 * 
 * @var array
 */
	public $mapMethods = array();
	
/**
 * HttpSocket unique instance
 * 
 * @var HttpSocket
 */
	protected $_Http = null;

/**
 * Default behavior params - initialized in constructor
 * 
 * @var array
 */
	protected $_defaults = array(
		'login' => null,
		'apiKey' => null);

/**
 * List of REST API methods
 *
 * @see http://code.google.com/p/bitly-api/wiki/ApiDocumentation#REST_API
 * @var array
 */
	private $__methods = array(
		'shorten' => array(
			'format' => array('json', 'xml'), 
			'method' => array('GET'), 
			'params' => array('longUrl' => 'required'),
			'url' => 'shorten'),
		'expand' => array(
			'format' => array('json', 'xml'), 
			'method' => array('GET'), 
			'params' => array('switch' => array('shortUrl', 'hash')),
			'url' => 'expand'),
		'info' => array(
			'format' => array('json', 'xml'), 
			'method' => array('GET'), 
			'params' => array('switch' => array('shortUrl', 'hash'), 'keys'),
			'url' => 'info'),
		'stats' => array(
			'format' => array('json', 'xml'), 
			'method' => array('GET'), 
			'params' => array('switch' => array('shortUrl', 'hash')),
			'url' => 'stats'),
		'errors' => array(
			'format' => array('json'),
			'method' => array('GET'),
			'params' => array(),
			'url' => 'errors')
	);
	
/**
 * Endpoint url of the API
 * 
 * @var string
 */
	private $__apiEndpoint = 'http://api.bit.ly/';

/**
 * Bit.ly API version used in this behavior
 *  
 * @var string
 */
	private $__version = '2.0.1';

/**
 * Map between the lowercased name of a method and its real name
 * 
 * @var array key => Value
 */
	private $__lowMap = array();

/**
 * Constructor
 * 	- Initializes credentials with Config keys
 * 
 * @return void
 */
	public function __construct() {
		parent::__construct();
		$this->_defaults = array(
			'login' => Configure::read('Bitly.login'),
			'apiKey' => Configure::read('Bitly.apiKey'),
		);
	}

/**
 * Setup
 *
 * @param AppModel $model
 * @param array $settings Settings to configure the bit.ly API. Possible setting keys are:
 * 	- login: API login
 * 	- apiKey: API key available in http://bit.ly/account/your_api_key
 * @return boolean Always true
 */
	public function setup(Model $Model, $settings = array()) {
		$this->settings[$Model->alias] = array_merge($this->_defaults, $settings);
		if (empty($this->settings[$Model->alias]['login']) || empty($this->settings[$Model->alias]['apiKey'])) {
			trigger_error(__('Invalid API access information. A login and an API key are needed.', true), E_USER_ERROR);
		}
		
		$methods = array_keys($this->__methods);
		foreach ($methods as $method) {
			$this->__lowMap[strtolower($method)] = $method;
		}
		$this->mapMethods["/" . join('|', $methods) . "/"] = 'apiCall'; 
		if (empty($this->_Http)) {
			$this->_Http = ClassRegistry::init('HttpSocket');
		}
		return true;
	}
	
/**
 * Unique entry point for calling an API method
 * 
 * @throws BadMethodCallException If there are incorrect params
 * @throws BitlyException If an error is returned from the API
 * 
 * @param Model $Model
 * @param string $method Method name
 * @param array Parameters for the API method
 * @return array Result information, false otherwise
 */
	public function apiCall(&$Model, $method, $params = array()) {
		$return = false;
		$globalSettings = $this->settings[$Model->alias];
		$methodName = $this->__lowMap[$method];
		$settings = $this->__methods[$methodName];
		if (empty($params['params'])) {
			$params['params'] = array();
		}
		
		if ($this->__checkParams($methodName, $settings['params'], $params)) {
			$method = $this->__selectBest('method', $settings, $params);
			$format = $this->__selectBest('format', $settings, $params);
			$uri = $this->__generateUri($settings, $params);
			
			$params['params']['format'] = $format;
			$params['params']['login'] = $globalSettings['login'];
			$params['params']['apiKey'] = $globalSettings['apiKey'];
			$params['params']['version'] = $this->__version;
			ksort($params['params']);
			$response = $this->__doRequest($method, $uri, $params['params']);
			
			if ($response && in_array($format, array('xml', 'json'))) {
				$response['result'] = $this->__formatResponse($response['result'], $format);
			}
			if (isset($response['result']['statusCode']) && $response['result']['statusCode'] !== 'OK') {
				throw new BitlyException($response['result']['statusCode'], $response['result']['errorMessage'], $response['result']['errorCode']);
			} else {
				$response['result'] = $response['result']['results'];
			}
			$return = $response;
		}
		
		return $return;
	}
	
/**
 * Check passed parameters
 *
 * @throws BadMethodCallException If parameters are incorrect
 * @param string $method Method name
 * @param array $settings Params settings
 * @param array $params Passed params
 * @return boolean True on success
 */ 
	private function __checkParams($method, $settings, $params) {
		$result = array();
		$params = $params['params'];
		foreach ($settings as $param => $type) {
			if ($param === 'switch') {
				// Params with several possible names
				$found = false;
				foreach ($type as $switchParam) {
					if ($found !== false && isset($params[$switchParam])) {
						$errorMsg = 'Redundant parameter %s was found whereas the parameter %s was already passed for method %s. Please remove one of them.';
						throw new BadMethodCallException(sprintf($errorMsg, $switchParam, $found, $method));
					} elseif (isset($params[$switchParam])) {
						$found = $switchParam;
						unset($params[$switchParam]);
					}
				}
				if ($found === false) {
					throw new BadMethodCallException(sprintf('One of this parameter is needed for the method %s: %s', $method, join(', ', array_values($type))));
				}
			} elseif ($type == 'required') {
				if (isset($params[$param])) {
					unset($params[$param]);
				} else {
					throw new BadMethodCallException(sprintf('Required parameter %s not found for method %s.', $param, $method));
				}
			} elseif (isset($params[$type])) {
				unset($params[$type]);
			}
		}
		if (count($params) > 0) {
			throw new BadMethodCallException(sprintf('Unexpected parameters passed to method %s: %s', $method, join(', ', array_keys($params))));
		}
		return true;
	}
	
/**
 * Returns the best value of a setting, given default settings and passed parameters
 *
 * @param array $settings Method settings
 * @param array $params Passed params
 * @return string Best value to use
 */ 
	private function __selectBest($argType, $settings, $params) {
		if (!empty($params[$argType])) {
			$best = $params[$argType];
		} elseif (!empty($settings[$argType])) {
			$best = array_shift($settings[$argType]);
		} else {
			throw new InvalidArgumentException(sprintf('Incorrect setting name "%s".', $argType));
		}
		return $best;
	}

/**
 * Generate request uri based on request params and default values
 *
 * @param array $settings Method settings
 * @param array $params Passed params
 * @return string Uri
 */ 
	private function __generateUri($settings, $params) {
		$url = isset($settings['baseUrl']) ? $settings['baseUrl'] : $this->__apiEndpoint;
		$url .= $settings['url'];
		return $url;
	}

/**
 * Do a request to the API and returns the result
 * 
 * @throws InvalidArgumentException If the method argument is invalid
 * @param string $method Call method (e.g: GET, POST, PUT or DELETE
 * @param string $url Url to call
 * @param mixed $request Query string parameters, either in string form or as a keyed array
 * @return mixed False in case of error, response information otherwise: array with keys "result" and "code"
 */
	private function __doRequest($method, $url, $request = array()) {
		$result = false;
		$method = strtolower($method);
		if (!method_exists($this->_Http, $method)) {
			throw new InvalidArgumentException(sprintf('The method "%s" is not a valid socket method.', $method));
		}
		
		$response = $this->_Http->{strtolower($method)}($url, $request);
		if ($response !== false) {
			$result = array(
				'result' => $response,
				'code' => $this->_Http->response['status']['code'],
			);	
		}
		
		return $result;
	}

/**
 * Format the API response into an array, given the expected response type
 * 
 * @param array Response to format
 * @param string Response type
 * @return array Array formatted API response
 */
	private function __formatResponse($response, $format) {
		if (in_array($format, array('xml', 'rss', 'atom'))) {
			$xml = new XML($response);
			$array = $xml->toArray(false);
			unset($xml);	
			$response = $this->__fixXml($array);
			if (isset($response['bitly'])) {
				$response = array(
					'results' => $this->__fixBitlyXml($response['bitly']['results']),
				);
			}
		} elseif ($format === 'json') {
			$response = json_decode($response, true);
		}
		return $response;
	}

/**	
 * Fix array structure of XML->toArray() result
 *
 * @param array
 * @return array
 */
	private function __fixXml($data) {
		foreach ($data as $key => $value) {
			if (is_string($value)) {
				$data[$key] = preg_replace('/\\\x1A/', "\n", $value);
			}
			if (is_array($value) && count($value) == 0) {
				$data[$key] = '';
			} elseif (is_array($value)) {
				$data[$key] = $this->__fixXml($value);
			}
		}
		return $data;
	}

/**
 * Fix Bit.ly XML response
 * 	- Homogeneize array result with json response
 * 
 * @param array $data Returned data
 * @return array Modified data
 */
	private function __fixBitlyXml($data) {
		$result = array();
		if (!empty($data)) {
			foreach($data as $key => $val) {
				
				if ($key == 'doc' && is_array($val) && array_key_exists('hash', $val)) {
					$result[$val['hash']] = $val;
				
				} elseif (is_array($val) && array_key_exists('nodeKey', $val)) {
					$result[$val['nodeKey']] = $val;
					unset($result[$val['nodeKey']]['nodeKey']);
					$result[$val['nodeKey']] = $this->__fixBitlyXml($result[$val['nodeKey']]);
				
				} elseif (is_array($val) && array_key_exists('nodeKeyVal', $val)) {
					$result[$key] = $this->__fixBitlyXml($data[$key]['nodeKeyVal']);
				
				} elseif ($key == 'nodeKeyVal') {
					$result = $this->__fixBitlyXml($val);
					break;
					
				} elseif ($key == 'nodeValue') {
					$result = $val;
					break;
					
				} else {
					$result[$key] = $val;
				}
				
			}
		}
		return $result;
	}
}

/**
 * Custom Exception thrown when an error is returned from Bitly API
 * 
 */
class BitlyException extends RuntimeException {

/**
 * Status code for the error
 * 
 * @var string
 */
	protected $_statusCode = null;
	
/**
 * Constructor
 * 
 * @param array $cart Cart information to transmit with the exception
 * @param string $message
 * @param int $code
 * @return void
 */
	public function __construct($statusCode = '', $message = NULL, $code = 0) {
		parent::__construct($message, $code);
		$this->_statusCode = $statusCode;
	}
	
/**
 * Returns the status code returned by the API
 * 
 * @return string
 */
	final public function getStatusCode() {
		return $this->_statusCode;
	}
}
