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

$MerchantID = Mage::getStoreConfig('codisto/merchantid');
$HostKey = Mage::getStoreConfig('codisto/hostkey');

if(!isset($MerchantID) || !isset($HostKey))
{
	// load admin/user so that cookie deserialize will work properly
	Mage::getModel("admin/user");

	// get the admin session
	$session = Mage::getSingleton('admin/session');

	// get the user object from the session
	$user = $session->getUser();
	if(!$user)
	{
		$user = Mage::getModel('admin/user')->getCollection()->getFirstItem();
	}

	// get the request so we can build url
	$request = Mage::app()->getRequest();

	try
	{
		$client = new Zend_Http_Client("https://ui.codisto.com/create", array( 'keepalive' => true, 'maxredirects' => 0 ));
		$client->setHeaders('Content-Type', 'application/json');

		for($retry = 0; ; $retry++)
		{
			try
			{
				$url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
				$version = Mage::getVersion();
				$storename = Mage::getStoreConfig('general/store_information/name');
				$email = $user->getEmail();

				$remoteResponse = $client->setRawData(json_encode(array( 'type' => 'magento', 'version' => Mage::getVersion(), 'url' => $url, 'email' => $email, 'storename' => $storename )))->request('POST');

				if(!$remoteResponse->isSuccessful())
					throw new Exception('Error Creating Account');

				$data = json_decode($remoteResponse->getRawBody(), true);

				if(isset($data['merchantid']) && $data['merchantid'] &&
					isset($data['hostkey']) && $data['hostkey'])
				{
					Mage::getModel("core/config")->saveConfig("codisto/merchantid", $data['merchantid']);
					Mage::getModel("core/config")->saveConfig("codisto/hostkey", $data['hostkey']);
				}
			}
			catch(Exception $e)
			{
				if($retry < 3)
				{
					usleep(1000000);
					continue;
				}

				throw $e;
			}

			break;
		}
	}
	catch(Exception $e)
	{

	}
}

$indexer = Mage::getModel('index/process');
$indexer->load('codistoebayindex', 'indexer_code')
			->changeStatus(Mage_Index_Model_Process::STATUS_REQUIRE_REINDEX)
			->reindexAll();
