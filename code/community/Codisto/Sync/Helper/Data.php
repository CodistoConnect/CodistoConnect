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

			$hashOK = $Hash = $checkHash;
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
