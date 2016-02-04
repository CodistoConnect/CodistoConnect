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
$helper = Mage::helper('codisto-sync');
$MerchantID = Mage::getStoreConfig('codisto/merchantid', 0);
$HostKey = Mage::getStoreConfig('codisto/hostkey', 0);

$reindexRequired = true;

if(!isset($MerchantID) || !isset($HostKey))
{
	$request = Mage::app()->getRequest();

	$createMerchant = false;

	$path = $request->getPathInfo();

	if(!preg_match('/^\/codisto-sync\//', $path))
	{
		try
		{

			if(!extension_loaded('pdo'))
			{
				throw new Exception('(PHP Data Objects) please refer to <a target="#blank" href="http://help.codisto.com/article/64-what-is-pdoexception-could-not-find-driver">Codisto help article</a>', 999);
			}

			if(!in_array("sqlite",PDO::getAvailableDrivers(), TRUE))
			{
				throw new PDOException('(sqlite PDO Driver) please refer to <a target="#blank" href="http://help.codisto.com/article/64-what-is-pdoexception-could-not-find-driver">Codisto help article</a>', 999);
			}

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

		catch(Exception $e)
		{
			try
			{
				$url = ($request->getServer('SERVER_PORT') == '443' ? 'https://' : 'http://') . $request->getServer('HTTP_HOST') . $request->getServer('REQUEST_URI');
				$magentoversion = Mage::getVersion();
				$codistoversion = $helper->getCodistoVersion();

				$logEntry = Zend_Json::encode(array(
						'url' => $url,
						'magento_version' => $magentoversion,
						'codisto_version' => $codistoversion,
						'message' => $e->getMessage(),
						'code' => $e->getCode(),
						'file' => $e->getFile(),
						'line' => $e->getLine()));

				Mage::log('CodistoConnect '.$logEntry);

				$client = new Zend_Http_Client("https://ui.codisto.com/installed", array( 'adapter' => 'Zend_Http_Client_Adapter_Curl', 'curloptions' => array(CURLOPT_SSL_VERIFYPEER => false), 'keepalive' => false, 'maxredirects' => 0 ));
				$client->setHeaders('Content-Type', 'application/json');
				$client->setRawData($logEntry);
				$client->request('POST');
			}
			catch(Exception $e2)
			{

			}
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
					$url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
					$version = Mage::getVersion();
					$storename = Mage::getStoreConfig('general/store_information/name', 0);
					$email = $user->getEmail();
					$codistoversion = $helper->getCodistoVersion();

					$ResellerKey = Mage::getConfig()->getNode('codisto/resellerkey');
					if($ResellerKey)
					{
						$ResellerKey = intval(trim((string)$ResellerKey));
					}
					else
					{
						$ResellerKey = '0';
					}

					$client = new Zend_Http_Client("https://ui.codisto.com/create", array( 'keepalive' => true, 'maxredirects' => 0 ));
					$client->setHeaders('Content-Type', 'application/json');

					for($retry = 0; ; $retry++)
					{
						try
						{
							$remoteResponse = $client->setRawData(Zend_Json::encode(array( 'type' => 'magento', 'version' => Mage::getVersion(),
								'url' => $url, 'email' => $email, 'storename' => $storename , 'resellerkey' => $ResellerKey, 'codistoversion' => $codistoversion)))->request('POST');

							if(!$remoteResponse->isSuccessful())
								throw new Exception('Error Creating Account');

							// @codingStandardsIgnoreStart
							$data = Zend_Json::decode($remoteResponse->getRawBody(), true);
							// @codingStandardsIgnoreEnd

							if(isset($data['merchantid']) && $data['merchantid'] &&
								isset($data['hostkey']) && $data['hostkey'])
							{
								Mage::getModel("core/config")->saveConfig("codisto/merchantid", $data['merchantid']);
								Mage::getModel("core/config")->saveConfig("codisto/hostkey", $data['hostkey']);

								$reindexRequired = true;

								$MerchantID = $data['merchantid'];
								$HostKey = $data['hostkey'];

								try {

									$h = new Zend_Http_Client();
									$h->setConfig(array( 'keepalive' => true, 'maxredirects' => 0, 'timeout' => 20 ));
									$h->setStream();
									$h->setUri('https://ui.codisto.com/'.$MerchantID.'/testendpoint/');
									$h->setHeaders('X-HostKey', $HostKey);
									$testResponse = $h->request('GET');

									$testdata = Zend_Json::decode($testResponse->getRawBody(), true);

									if(isset($testdata['ack']) && $testdata['ack'] == "FAILED") {

										//Endpoint Unreachable - Turn on cron fallback
										$file = new Varien_Io_File();
										$file->open(array('path' => Mage::getBaseDir('var')));
										$file->write('codisto-external-sync-failed', '0');
										$file->close();

									}

								} catch (Exception $e) {

									//Check in cron
									$file = new Varien_Io_File();
									$file->open(array('path' => Mage::getBaseDir('var')));
									$file->write('codisto-external-test-failed', '0');
									$file->close();

									Mage::log('Error testing endpoint and writing failed sync file. Message: ' . $e->getMessage() . ' on line: ' . $e->getLine());

								}

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
}

if($reindexRequired)
{
	try {

		$indexer = Mage::getModel('index/process');
		$indexer->load('codistoebayindex', 'indexer_code')
				->changeStatus(Mage_Index_Model_Process::STATUS_REQUIRE_REINDEX)
				->reindexAll();

	} catch (Exception $e) {

	}
}
