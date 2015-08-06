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

$MerchantID = Mage::getStoreConfig('codisto/merchantid', 0);
$HostKey = Mage::getStoreConfig('codisto/hostkey', 0);
$ResellerKey = Mage::getConfig()->getNode('codisto/resellerkey');

$reindexRequired = true;

if(!isset($MerchantID) || !isset($HostKey))
{
	$createMerchant = false;

	try
	{
		$lockFile = Mage::getBaseDir('var') . '/codisto-lock';

		$lockDb = new PDO('sqlite:' . $lockFile);
		$lockDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$lockDb->setAttribute(PDO::ATTR_TIMEOUT, 1);
		$lockDb->exec('BEGIN EXCLUSIVE TRANSACTION');
		$lockDb->exec('CREATE TABLE IF NOT EXISTS Lock (id real NOT NULL)');

		$lockQuery = $lockDb->query('SELECT id FROM Lock UNION SELECT 0 WHERE NOT EXISTS(SELECT 1 FROM Lock)');
		$lockQuery->execute();
		$lockRow = $lockQuery->fetch();
		$timeStamp = $lockRow['id'];

		if($timeStamp + 300000 < microtime(true))
		{
			$createMerchant = true;

			$lockDb->exec('DELETE FROM Lock');
			$lockDb->exec('INSERT INTO Lock (id) VALUES('. microtime(true) .')');
		}

		$lockDb->exec('COMMIT TRANSACTION');
		$lockDb = null;
	}
	catch (Exception $e)
	{

	}

	$reindexRequired = false;

	if($createMerchant)
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
			$createLockFile = Mage::getBaseDir('var') . '/codisto-create-lock';

			$createLockDb = new PDO('sqlite:' . $createLockFile);
			$createLockDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$createLockDb->setAttribute(PDO::ATTR_TIMEOUT, 60);
			$createLockDb->exec('BEGIN EXCLUSIVE TRANSACTION');

			Mage::app()->getStore(0)->resetConfig();

			$MerchantID = Mage::getStoreConfig('codisto/merchantid', 0);
			$HostKey = Mage::getStoreConfig('codisto/hostkey', 0);
			
			if(!isset($MerchantID) || !isset($HostKey))
			{
				$client = new Zend_Http_Client("https://ui.codisto.com/create", array( 'keepalive' => true, 'maxredirects' => 0 ));
				$client->setHeaders('Content-Type', 'application/json');

				for($retry = 0; ; $retry++)
				{
					try
					{
						$url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
						$version = Mage::getVersion();
						$storename = Mage::getStoreConfig('general/store_information/name', 0);
						$email = $user->getEmail();

						$remoteResponse = $client->setRawData(json_encode(array( 'type' => 'magento', 'version' => Mage::getVersion(),
							'url' => $url, 'email' => $email, 'storename' => $storename , 'resellerkey' => $ResellerKey)))->request('POST');

						if(!$remoteResponse->isSuccessful())
							throw new Exception('Error Creating Account');

						// @codingStandardsIgnoreStart
						$data = json_decode($remoteResponse->getRawBody(), true);
						// @codingStandardsIgnoreEnd

						if(isset($data['merchantid']) && $data['merchantid'] &&
							isset($data['hostkey']) && $data['hostkey'])
						{
							Mage::getModel("core/config")->saveConfig("codisto/merchantid", $data['merchantid']);
							Mage::getModel("core/config")->saveConfig("codisto/hostkey", $data['hostkey']);

							$reindexRequired = true;
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

			$createLockDb->exec('COMMIT TRANSACTION');
			$createLockDb = null;
		}
		catch(Exception $e)
		{
			// remove lock file immediately if any error during account creation
			@unlink($lockFile);
		}
	}
}

if($reindexRequired)
{
	$indexer = Mage::getModel('index/process');
	$indexer->load('codistoebayindex', 'indexer_code')
			->changeStatus(Mage_Index_Model_Process::STATUS_REQUIRE_REINDEX)
			->reindexAll();
}
