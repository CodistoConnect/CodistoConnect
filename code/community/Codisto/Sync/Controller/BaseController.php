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

abstract class Codisto_Sync_Controller_BaseController extends Mage_Core_Controller_Front_Action
{
	protected function checkHash($HostKey, $Nonce, $Hash)
	{
		$response = $this->getResponse();
		$r = $HostKey . $Nonce;
		$base = hash('sha256', $r, true);
		$checkHash = base64_encode($base);
		if ($Hash != $checkHash)
		{
			$response->setBody('Hash Mismatch Error.');
		}
		return true;
	}

	protected function getConfig()
	{
		$this->config = array(
			'MerchantID' => Mage::getStoreConfig('codisto/merchantid'),
			'HostKey' => Mage::getStoreConfig('codisto/hostkey')
		);

		if(!isset($this->config['MerchantID']) || $this->config['MerchantID'] == '' ||
			!isset($this->config['HostKey']) || $this->config['HostKey'] == '')
		{
			return false;
		}

		return true;
	}
}
