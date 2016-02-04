<?php
/**
* Codisto eBay Sync Extension
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@magentocommerce.com so we can send you a copy immediately.
*
* @category    Codisto
* @package     Codisto_Sync
* @copyright   Copyright (c) 2015 On Technology Pty. Ltd. (http://codisto.com/)
* @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*/


if (!function_exists('hash_equals')) {

	function hash_equals($known_string, $user_string)
	{

		/**
		 * This file is part of the hash_equals library
		 *
		 * For the full copyright and license information, please view the LICENSE
		 * file that was distributed with this source code.
		 *
		 * @copyright Copyright (c) 2013-2014 Rouven Weßling <http://rouvenwessling.de>
		 * @license http://opensource.org/licenses/MIT MIT
		 */

		// We jump trough some hoops to match the internals errors as closely as possible
		$argc = func_num_args();
        $params = func_get_args();

        if ($argc < 2) {
            trigger_error("hash_equals() expects at least 2 parameters, {$argc} given", E_USER_WARNING);
            return null;
        }

        if (!is_string($known_string)) {
        	trigger_error("hash_equals(): Expected known_string to be a string, " . gettype($known_string) . " given", E_USER_WARNING);
            return false;
        }
        if (!is_string($user_string)) {
        	trigger_error("hash_equals(): Expected user_string to be a string, " . gettype($user_string) . " given", E_USER_WARNING);
            return false;
        }

        if (strlen($known_string) !== strlen($user_string)) {
        	return false;
        }
		$len = strlen($known_string);
		$result = 0;
        for ($i = 0; $i < $len; $i++) {
            $result |= (ord($known_string[$i]) ^ ord($user_string[$i]));
        }
        // They are only identical strings if $result is exactly 0...
        return 0 === $result;
	}
}


class Codisto_Sync_Helper_Data extends Mage_Core_Helper_Abstract
{
	public function getCodistoVersion()
	{
		return (string) Mage::getConfig()->getNode()->modules->Codisto_Sync->version;
	}

	public function checkHash($Response, $HostKey, $Nonce, $Hash)
	{
		$hashOK = false;

		if(isset($Response)) {

			$r = $HostKey . $Nonce;
			$base = hash('sha256', $r, true);
			$checkHash = base64_encode($base);

			$hashOK = hash_equals($Hash ,$checkHash);
		}

		return $hashOK;

	}

	public function getConfig($storeId)
	{
		$merchantID = Mage::getStoreConfig('codisto/merchantid', $storeId);
		$hostKey = Mage::getStoreConfig('codisto/hostkey', $storeId);

		return isset($merchantID) && $merchantID != ""	&&	isset($hostKey) && $hostKey != "";
	}
}
