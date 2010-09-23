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
 * Bit.ly Access Model
 *
 * @package bitly
 * @subpackage bitly.models
 */
class BitlyAccess extends AppModel {

/**
 * Name
 *
 * @var string Name
 */
	public $name = 'BitlyAccess';

/**
 * Table
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

/**
 * Returns the unique bit.ly hash of an url
 * 
 * @param string $url Url to find the hash for
 * @return mixed Unique bit.ly hash, or false if an error occured with bit.ly
 */
	public function getHash($url = '') {
		$hash = false;
		if (!empty($url)) {
			preg_match('/(http:\/\/)bit.ly\/(.*)/', $url, $infos);
			try {
				if (empty($infos[0])) {
					// It is not a bit.ly shortened url so we need to shorten it to get the canonical hash
					$params = array('longUrl' => $url);
					$infos = $this->shorten(compact('params'));
				} else {
					// It is a bit.ly shortened url, we need to call infos method to get the canonical hash
					$url = $infos[2];
					$params = array('hash' => $url, 'keys' => 'hash');
					$infos = $this->info(compact('params'));
				}
				if ($infos['code'] == 200 && !empty($infos['result'][$url]['hash'])) {
					$hash = $infos['result'][$url]['hash'];
				}
			} catch(Exception $e) {
				if (Configure::read('debug') > 0){
					debug($e->getMessage());
				} else {
					$this->log(sprintf('An error occured when getting infos for the "%s" url. Message: %s', $url, $e->getMessage()));
				}
			}
		}
		return $hash;
	}
}
