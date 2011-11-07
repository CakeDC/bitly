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

App::import('Core', 'HttpSocket');
Mock::generate('HttpSocket');

/**
 * Model acting as the bit.ly API during this test case
 *
 * @package bitly
 * @subpackage bitly.tests.cases.behaviors
 */
class ModelTest extends Model {

/**
 * Table to user
 *
 * @var mixed
 */
	public $useTable = false;

/**
 * Behaviors
 *
 * @var array
 */
	public $actsAs = array('Bitly.BitlyApi');
}

/**
 * Bitly API Behavior Test
 *
 * @package bitly
 * @subpackage bitly.tests.cases.behaviors
 */
class BitlyApiBehaviorTest extends CakeTestCase {
	
/**
 * Model acting as Bitly API
 * 
 * @var ModelTest
 */
	public $Model = null;
	
/**
 * Instance of the tested behavior
 * 
 * @var BitlyApiBehavior
 */
	public $BitlyApi = null;
	
/**
 * Mock HttpSocket instance
 * 
 * @var HttpSocket
 */
	public $Http = null;
	
/**
 * Whether or not the test case must use test credentials and mock HttpSocket to run the tests
 * If this param is false, tests might be red because responses are unpredictable but it is a quick way 
 * to see whether the application is properly configured or not and if the bit.ly API has changed
 * 
 * @var boolean
 */
	private $__useTestSocket = true;
	
/**
 * Start test callback
 * 
 * @return void
 */
	public function startTest($method) {
		parent::startTest($method);
		$this->Http = new MockHttpSocket();
		ClassRegistry::removeObject('http_socket');
		if ($this->__useTestSocket) {
			ClassRegistry::addObject('http_socket', $this->Http);
		}

		if ($this->__useTestSocket) {
			Configure::write('Bitly', array('login' => 'testLogin', 'apiKey' => 'testApiKey'));
		}
		$this->Model = ClassRegistry::init('ModelTest');
		$this->BitlyApi = $this->Model->Behaviors->BitlyApi;
	}

/**
 * End test callback
 * 
 * @return void
 */
	public function endTest($method) {
		parent::endTest($method);
		unset($this->Model, $this->BitlyApi, $this->Http);
		ClassRegistry::flush();
	}
	
/**
 * Test behavior instance
 * 
 * @return void
 */
	public function testInstance() {
		$this->assertIsA($this->BitlyApi, 'BitlyApiBehavior');
	}

/**
 * Test setup method
 * 
 * @return void
 */
	public function testSetup() {
		$this->assertTrue($this->BitlyApi->setup($this->Model, array('login' => 'testLogin', 'apiKey' => 'testApiKey')));
		
		$methods = array('shorten', 'expand', 'info', 'stats', 'errors');
		$expectedMapping = array(
			'/' . join('|', $methods) . '/' => 'apiCall'
		);
		$this->assertEqual($this->BitlyApi->mapMethods, $expectedMapping);
		$this->assertEqual($this->BitlyApi->settings, array($this->Model->alias => array('login' => 'testLogin', 'apiKey' => 'testApiKey')));
		
		$this->expectError('Invalid API access information. A login and an API key are needed.');
		$this->BitlyApi->setup($this->Model, array('login' => null));
	}

/**
 * Test shorten API call along with some common use case: API error, incorrect parameters (mandatory, too many...)
 * 
 * @return void
 */
	public function testShorten() {
		$params = array();
		try {
			$this->Model->shorten(compact('params'));
			$this->fail();
		} catch(BadMethodCallException $e) {
			$this->pass();
			$this->assertEqual($e->getMessage(), 'Required parameter longUrl not found for method shorten.');
		}

		$getCallCount = 0;
		$this->Http->response['status']['code'] = 200;
		// Test normal call
		$params['longUrl'] = 'http://foo.bar/long/url.html';
		$expectedParams = array(
			'http://api.bit.ly/shorten',
			array(
				'longUrl' => 'http://foo.bar/long/url.html',
				'format' => 'json',
				'login' => 'testLogin',
				'apiKey' => 'testApiKey',
				'version' => '2.0.1',
			)
		);
		ksort($expectedParams[1]);
		$shortenedUrlInfo = array(
			'http://foo.bar/long/url.html' => array(
				'hash' => 'ayQ9ef',
				'shortCNAMEUrl' => 'http://bit.ly/8ZYEGc',
				'shortKeywordUrl' => '',
				'shortUrl' => 'http://bit.ly/8ZYEGc',
				'userHash' => '8ZYEGc',
			)
		);
		$response = json_encode(array(
			'errorCode' => 0,
			'errorMessage' => '',
			'results' => $shortenedUrlInfo,
			'statusCode' => 'OK',
		));
		$this->Http->expectAt($getCallCount, 'get', $expectedParams);
		$this->Http->setReturnValueAt($getCallCount++, 'get', $response);
		$result = $this->Model->shorten(compact('params'));
		$expected = array('result' => $shortenedUrlInfo, 'code' => 200);
		$this->assertEqual($result, $expected);
		
		// Test XML call
		$expectedParams[1]['format'] = 'xml';
		$response = 
		'<bitly>
			<errorCode>0</errorCode>
			<errorMessage></errorMessage>
			<results>
				<nodeKeyVal>
					<shortKeywordUrl></shortKeywordUrl>
					<hash>ayQ9ef</hash>
					<userHash>8ZYEGc</userHash>
					<nodeKey><![CDATA[http://foo.bar/long/url.html]]></nodeKey>
					<shortUrl>http://bit.ly/8ZYEGc</shortUrl>
					<shortCNAMEUrl>http://bit.ly/8ZYEGc</shortCNAMEUrl>
				</nodeKeyVal>
			</results>
			<statusCode>OK</statusCode>
		</bitly>';
		$this->Http->expectAt($getCallCount, 'get', $expectedParams);
		$this->Http->setReturnValueAt($getCallCount++, 'get', $response);
		$result = $this->Model->shorten(array('format' => 'xml', 'params' => $params));
		$expected = array('result' => $shortenedUrlInfo, 'code' => 200);
		$this->assertEqual($result, $expected);
		
		// Test error from bit.ly
		$expectedParams[1]['format'] = 'json';
		$response = json_encode(array(
			'errorCode' => 203,
			'errorMessage' => 'You must be authenticated to access shorten',
			'statusCode' => 'ERROR'
		));
		$this->Http->expectAt($getCallCount, 'get', $expectedParams);
		$this->Http->setReturnValueAt($getCallCount++, 'get', $response);
		try {
			$this->Model->shorten(compact('params'));
			$this->fail();
		} catch(BitlyException $e) {
			$this->pass();
			$this->assertEqual($e->getStatusCode(), 'ERROR');
		}

		// Test too many params
		$params['foo'] = 'bar';
		$params['another'] = 'param';
		try {
			$this->Model->shorten(compact('params'));
			$this->fail();
		} catch(BadMethodCallException $e) {
			$this->pass();
			$this->assertEqual($e->getMessage(), 'Unexpected parameters passed to method shorten: foo, another');
		}
		
		if ($this->__useTestSocket) {
			$this->Http->expectCallCount('get', $getCallCount);
		}
	}

/**
 * Test expand API call along with switch parameteres related errors (incorrect param, two values...)
 * 
 * @return void
 */
	public function testExpand() {
		$params = array();
		try {
			$this->Model->expand(compact('params'));
			$this->fail();
		} catch(BadMethodCallException $e) {
			$this->pass();
			$this->assertEqual($e->getMessage(), 'One of this parameter is needed for the method expand: shortUrl, hash');
		}

		$getCallCount = 0;
		$this->Http->response['status']['code'] = 200;
		// Test normal call
		$params['hash'] = 'ayQ9ef';
		$expectedParams = array(
			'http://api.bit.ly/expand',
			array(
				'hash' => 'ayQ9ef',
				'format' => 'json',
				'login' => 'testLogin',
				'apiKey' => 'testApiKey',
				'version' => '2.0.1',
			)
		);
		ksort($expectedParams[1]);
		$responseData = array(
			'ayQ9ef' => array(
				'longUrl' => 'http://foo.bar/long/url.html'
			)
		);
		$response = json_encode(array(
			'errorCode' => 0,
            'errorMessage' => '',
			'results' => $responseData,
            'statusCode' => 'OK',
		));
		$this->Http->expectAt($getCallCount, 'get', $expectedParams);
		$this->Http->setReturnValueAt($getCallCount++, 'get', $response);
		$result = $this->Model->expand(compact('params'));
		$expected = array('result' => $responseData, 'code' => 200);
		$this->assertEqual($result, $expected);

		// Test XML call
		$expectedParams[1]['format'] = 'xml';
		$response = 
		'<bitly>
			<errorCode>0</errorCode>
			<errorMessage></errorMessage>
			<results>
				<ayQ9ef>
					<longUrl>http://foo.bar/long/url.html</longUrl>
				</ayQ9ef>
			</results>
			<statusCode>OK</statusCode>
		</bitly>';
		$this->Http->expectAt($getCallCount, 'get', $expectedParams);
		$this->Http->setReturnValueAt($getCallCount++, 'get', $response);
		$result = $this->Model->expand(array('format' => 'xml', 'params' => $params));
		$expected = array('result' => $responseData, 'code' => 200);
		$this->assertEqual($result, $expected);

		// Test too many params
		$params['shortUrl'] = 'http://bit.ly/8ZYEGc';
		try {
			$this->Model->expand(compact('params'));
			$this->fail();
		} catch(BadMethodCallException $e) {
			$this->pass();
			$this->assertEqual($e->getMessage(), 'Redundant parameter hash was found whereas the parameter shortUrl was already passed for method expand. Please remove one of them.');
		}
		
		if ($this->__useTestSocket) {
			$this->Http->expectCallCount('get', $getCallCount);
		}
	}

/**
 * Test info API call
 * 
 * @return void
 */
	public function testInfo() {
		$params = array();
		try {
			$this->Model->info(compact('params'));
			$this->fail();
		} catch(BadMethodCallException $e) {
			$this->pass();
			$this->assertEqual($e->getMessage(), 'One of this parameter is needed for the method info: shortUrl, hash');
		}
		
		$getCallCount = 0;
		$this->Http->response['status']['code'] = 200;
		// Test normal call
		$params['hash'] = 'R8hVH'; // cakephp.org
		$expectedParams = array(
			'http://api.bit.ly/info',
			array(
				'hash' => 'R8hVH',
				'format' => 'json',
				'login' => 'testLogin',
				'apiKey' => 'testApiKey',
				'version' => '2.0.1',
			)
		);
		ksort($expectedParams[1]);
		$responseData = array(
			'R8hVH' => array(
				'calais' => array(),
				'calaisId' => '',
				'calaisResolutions' => array(),
				'contentLength' => '',
				'contentType' => 'text/html; charset=utf-8',
				'exif' => array(),
				'globalHash' => 'R8hVH',
				'hash' => 'R8hVH',
				'htmlMetaDescription' => '',
				'htmlMetaKeywords' => '',
				'htmlTitle' => 'CakePHP: the rapid development php framework. Home',
				'id3' => array(),
				'keyword' => '',
				'keywords' => array(),
				'longUrl' => 'http://cakephp.org',
				'metacarta' => array(),
				'mirrorUrl' => '',
				'shortenedByUser' => 'bitly',
				'surbl' => 0,
				'thumbnail' => array(
					'large' => 'http://s.bit.ly/bitly/R8hVH/thumbnail_large.png',
					'medium' => 'http://s.bit.ly/bitly/R8hVH/thumbnail_medium.png',
					'small' => 'http://s.bit.ly/bitly/R8hVH/thumbnail_small.png',
				),
				'userHash' => '',
				'users' => array(),
				'version' => '1.0',
			)
		);
		$response = json_encode(array(
			'errorCode' => 0,
            'errorMessage' => '',
			'results' => $responseData,
            'statusCode' => 'OK',
		));
		$this->Http->expectAt($getCallCount, 'get', $expectedParams);
		$this->Http->setReturnValueAt($getCallCount++, 'get', $response);
		$result = $this->Model->info(compact('params'));
		$expected = array('result' => $responseData, 'code' => 200);
		$this->assertEqual($result, $expected);
		
		// Test XML call
		$expectedParams[1]['format'] = 'xml';
		$response = 
		'<bitly>
			<errorCode>0</errorCode>
			<errorMessage></errorMessage>
			<results>
				<doc>
					<shortenedByUser>bitly</shortenedByUser>
					<keywords></keywords>
					<hash>R8hVH</hash>
					<exif></exif>
					<surbl>0</surbl>
					<contentLength></contentLength>
					<id3></id3>
					<calais></calais>
					<longUrl>http://cakephp.org</longUrl>
					<version>1.0</version>
					<htmlMetaDescription><![CDATA[]]></htmlMetaDescription>
					<htmlMetaKeywords></htmlMetaKeywords>
					<calaisId></calaisId>
					<thumbnail>
						<large>http://s.bit.ly/bitly/R8hVH/thumbnail_large.png</large>
						<small>http://s.bit.ly/bitly/R8hVH/thumbnail_small.png</small>
						<medium>http://s.bit.ly/bitly/R8hVH/thumbnail_medium.png</medium>
					</thumbnail>
					<contentType>text/html; charset=utf-8</contentType>
					<users></users>
					<globalHash>R8hVH</globalHash>
					<htmlTitle><![CDATA[CakePHP: the rapid development php framework. Home]]></htmlTitle>
					<metacarta></metacarta>
					<mirrorUrl></mirrorUrl>
					<keyword></keyword>
					<calaisResolutions></calaisResolutions>
					<userHash></userHash>
				</doc>
			</results>
			<statusCode>OK</statusCode>
		</bitly>';
		$this->Http->expectAt($getCallCount, 'get', $expectedParams);
		$this->Http->setReturnValueAt($getCallCount++, 'get', $response);
		$result = $this->Model->info(array('format' => 'xml', 'params' => $params));
		// Expected empty arrays will be empty string in the response because of Xml conversion 
		$newResponseData = $responseData;
		foreach($newResponseData['R8hVH'] as $key => $val) {
			if ($val === array()) {
				$newResponseData['R8hVH'][$key] = '';
			}
		}
		$expected = array('result' => $newResponseData, 'code' => 200);
		$this->assertEqual($result, $expected); 

		// Test returned keys limit
		$expectedParams[1]['keys'] = $params['keys'] = 'hash,htmlTitle';
		$expectedParams[1]['format'] = 'json';
		ksort($expectedParams[1]);

		$responseData['R8hVH'] = array_intersect_key($responseData['R8hVH'], array_flip(explode(',', $params['keys'])));
		$response = json_encode(array(
			'errorCode' => 0,
			'errorMessage' => '',
			'results' => $responseData,
			'statusCode' => 'OK',
		));
		$this->Http->expectAt($getCallCount, 'get', $expectedParams);
		$this->Http->setReturnValueAt($getCallCount++, 'get', $response);
		$result = $this->Model->info(compact('params'));
		$expected = array('result' => $responseData, 'code' => 200);
		$this->assertEqual($result, $expected); 

		if ($this->__useTestSocket) {
			$this->Http->expectCallCount('get', $getCallCount);
		}
	}

/**
 * Test stats API call
 * 
 * @return void
 */
	public function testStats() {
		$getCallCount = 0;
		$this->Http->response['status']['code'] = 200;
		// Test normal call
		$params['hash'] = 'R8hVH'; // cakephp.org
		$expectedParams = array(
			'http://api.bit.ly/stats',
			array(
				'hash' => 'R8hVH',
				'format' => 'json',
				'login' => 'testLogin',
				'apiKey' => 'testApiKey',
				'version' => '2.0.1',
			)
		);
		ksort($expectedParams[1]);
		$responseData = array(
			'clicks' => 6,
			'hash' => 'R8hVH',
			'referrers' => array(
				'' => array(
					'direct' => 4,
				),
				'twitter.com' => array(
					'/' => 1,
					'/home' => 1,
				),
			),
		);
		$response = json_encode(array(
			'errorCode' => 0,
			'errorMessage' => '',
			'results' => $responseData,
			'statusCode' => 'OK',
		));
		$this->Http->expectAt($getCallCount, 'get', $expectedParams);
		$this->Http->setReturnValueAt($getCallCount++, 'get', $response);
		$result = $this->Model->stats(compact('params'));
		$expected = array('result' => $responseData, 'code' => 200);
		$this->assertEqual($result, $expected);

		// Test XML call
		$expectedParams[1]['format'] = 'xml';
		$response = 
		'<bitly>
			<errorCode>0</errorCode>
			<errorMessage></errorMessage>
			<results>
				<referrers>
					<nodeKeyVal>
						<direct>4</direct>
						<nodeKey><![CDATA[]]></nodeKey>
					</nodeKeyVal>
					<nodeKeyVal>
						<nodeKey><![CDATA[twitter.com]]></nodeKey>
						<nodeKeyVal>
							<nodeValue><![CDATA[1]]></nodeValue>
							<nodeKey><![CDATA[/]]></nodeKey>
						</nodeKeyVal>
						<nodeKeyVal>
							<nodeValue><![CDATA[1]]></nodeValue>
							<nodeKey><![CDATA[/home]]></nodeKey>
						</nodeKeyVal>
					</nodeKeyVal>
				</referrers>
				<hash>R8hVH</hash>
				<clicks>6</clicks>
			</results>
			<statusCode>OK</statusCode>
		</bitly>';
		$this->Http->expectAt($getCallCount, 'get', $expectedParams);
		$this->Http->setReturnValueAt($getCallCount++, 'get', $response);
		$result = $this->Model->stats(array('format' => 'xml', 'params' => $params));
		$expected = array('result' => $responseData, 'code' => 200);
		$this->assertEqual($result, $expected); 
		
		if ($this->__useTestSocket) {
			$this->Http->expectCallCount('get', $getCallCount); 
		}
	}
}
