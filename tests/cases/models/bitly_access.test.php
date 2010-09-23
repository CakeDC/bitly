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

App::import('Core', 'ModelBehavior');
Mock::generatePartial('ModelBehavior', 'MockBitlyApiBehavior', array('info', 'shorten'));

/**
 * Bitly Access Test Case
 *
 * @package bitly
 * @subpackage bitly.tests.cases.models
 */
class BitlyAccessTestCase extends CakeTestCase {

/**
 * Model being tested
 * 
 * @var BitlyAccess
 */
	public $BitlyAccess = null;
	
/**
 * Mock bit.ly Api behavior
 * 
 * @var MockBitlyApiBehavior
 */
	public $BitlyApi = null;
	
/**
 * Whether or not the test case must use a mock object for the API
 * Setting this attribute to false will make some tests fail, but it is a good way to test the real API integration
 * 
 * @var boolean
 */
	private $__testWithMockApi = true;
	
/**
 * Start test callback
 * 
 * @param string $method Test being executed
 * @return void
 */
	public function startTest($method) {
		parent::startTest($method);
		$this->BitlyApi = new MockBitlyApiBehavior();
		if ($this->__testWithMockApi) {
			Classregistry::removeObject('bitly_api_behavior');
			Classregistry::addObject('bitly_api_behavior', $this->BitlyApi);
		}
		$this->BitlyAccess = ClassRegistry::init('Bitly.BitlyAccess');
	}

/**
 * End test callback
 * 
 * @param string $method Test being executed
 * @return void
 */
	public function endTest($method) {
		parent::endTest($method);
		unset($this->BitlyApi, $this->BitlyAccess);
		ClassRegistry::flush();
	}

/**
 * Test objects proper creation
 * 
 * @return void
 */
	public function testInstance() {
		$this->assertIsA($this->BitlyAccess, 'BitlyAccess');
		$this->assertIsA($this->BitlyApi, 'MockBitlyApiBehavior');
	}

/**
 * Test getHash method
 * 
 * @return void
 */
	public function testGetHash() {
		$callCount = array(
			'info' => 0,
			'shorten' => 0);
		$this->assertFalse($this->BitlyAccess->getHash());

		// Test shortening an url to get the hash
		$expectedParams = array('*', array(
			'params' => array('longUrl' => 'http://foo.bar/long/url.html')));

		$response = array(
			'result' => array(
				'http://foo.bar/long/url.html' => array(
					'hash' => 'ayQ9ef',
					'shortCNAMEUrl' => 'http://bit.ly/8ZYEGc',
					'shortKeywordUrl' => '',
					'shortUrl' => 'http://bit.ly/8ZYEGc',
					'userHash' => '8ZYEGc',
				),
			),
			'code' => 200,
		);
		$this->BitlyApi->expectAt($callCount['shorten'], 'shorten', $expectedParams);
		$this->BitlyApi->setReturnValueAt($callCount['shorten']++, 'shorten', $response);
		$this->assertEqual($this->BitlyAccess->getHash('http://foo.bar/long/url.html'), 'ayQ9ef');

		if ($this->__testWithMockApi) {
			$this->BitlyApi->expectAt($callCount['shorten']++, 'shorten', $expectedParams);
			$this->BitlyApi->throwOn('shorten', new Exception('Ouch!'));
			$this->assertEqual($this->BitlyAccess->getHash('http://foo.bar/long/url.html'), false);
		}

		// Test getting infos from a bit.ly url
		$expectedParams = array('*', array(
			'params' => array(
				'hash' => 'foobar',
				'keys' => 'hash',
			),
		));
		$response = array(
			'result' => array(
				'foobar' => array(
					'hash' => 'foobar')), 
			'code' => 200);

		$this->BitlyApi->expectAt($callCount['info'], 'info', $expectedParams);
		$this->BitlyApi->setReturnValueAt($callCount['info']++, 'info', $response);
		$this->assertEqual($this->BitlyAccess->getHash('http://bit.ly/foobar'), 'foobar');

		if ($this->__testWithMockApi) {
			$this->BitlyApi->expectAt($callCount['info']++, 'info', $expectedParams);
			$this->BitlyApi->throwOn('info', new Exception('Ouch!'));
			$this->assertEqual($this->BitlyAccess->getHash('http://bit.ly/foobar'), false);

			$this->BitlyApi->expectCallCount('info', $callCount['info']);
			$this->BitlyApi->expectCallCount('shorten', $callCount['shorten']);
		}
	}
}
