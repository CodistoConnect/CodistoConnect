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

class Codisto_Sync_SyncController extends Codisto_Sync_Controller_BaseController
{
	private $defaultSyncTimeout = 10;
	private $defaultSleep = 100000;

	public function indexAction()
	{
		$response = $this->getResponse();
		$request = $this->getRequest();
		$request->setDispatched(true);
		$server = $request->getServer();

		$storeId = $request->getQuery('storeid') == null ? 0 : (int)$request->getQuery('storeid');

		if(!$this->getConfig($storeId))
		{
			//@codingStandardsIgnoreStart
			if(function_exists('http_response_code'))
				http_response_code(500);
			//@codingStandardsIgnoreEnd
			$response->setHttpResponseCode(500);
			$response->setRawHeader('HTTP/1.0 500 Security Error');
			$response->setRawHeader('Status: 500 Security Error');
			$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
			$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
			$response->setHeader('Pragma', 'no-cache', true);
			$response->setBody('Config Error');
			return;
		}

		if (isset($server['HTTP_X_SYNC'])) {
			if (!isset($server['HTTP_X_ACTION'])) {
				$server['HTTP_X_ACTION'] = '';
			}

			switch ($server['HTTP_X_ACTION']) {

				case 'GET':

					if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

						if($request->getQuery('productid') || $request->getQuery('categoryid') || $request->getQuery('orderid'))
						{
							if($request->getQuery('orderid'))
							{
								$orderIds = Zend_Json::decode($request->getQuery('orderid'));
								if(!is_array($orderIds))
									$orderIds = array($orderIds);

								$orderIds = array_map('intval', $orderIds);

								$syncObject = Mage::getModel('codistosync/sync');
								$syncObject->SyncOrders($syncDb, $orderIds, $storeId);
							}

							$tmpDb = tempnam(Mage::getBaseDir('var'), 'codisto-ebay-sync-');

							$db = new PDO('sqlite:' . $tmpDb);
							$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

							$db->exec('PRAGMA synchronous=0');
							$db->exec('PRAGMA temp_store=2');
							$db->exec('PRAGMA page_size=65536');
							$db->exec('PRAGMA encoding=\'UTF-8\'');
							$db->exec('PRAGMA cache_size=15000');
							$db->exec('PRAGMA soft_heap_limit=67108864');
							$db->exec('PRAGMA journal_mode=MEMORY');

							$db->exec('ATTACH DATABASE \''.$syncDb.'\' AS SyncDB');

							$db->exec('BEGIN EXCLUSIVE TRANSACTION');

							if($request->getQuery('categoryid'))
							{
								$db->exec('CREATE TABLE Category AS SELECT * FROM SyncDb.Category');
							}

							if($request->getQuery('productid'))
							{
								$productIds = Zend_Json::decode($request->getQuery('productid'));
								if(!is_array($productIds))
									$productIds = array($productIds);

								$productIds = array_map('intval', $productIds);

								$db->exec('CREATE TABLE Product AS SELECT * FROM SyncDb.Product WHERE ExternalReference IN ('.implode(',', $productIds).')');
								$db->exec('CREATE TABLE ProductImage AS SELECT * FROM SyncDb.ProductImage WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
								$db->exec('CREATE TABLE CategoryProduct AS SELECT * FROM SyncDb.CategoryProduct WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
								$db->exec('CREATE TABLE SKU AS SELECT * FROM SyncDb.SKU WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
								$db->exec('CREATE TABLE SKUMatrix AS SELECT * FROM SyncDb.SKUMatrix WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product))');
								$db->exec('CREATE TABLE SKUImage AS SELECT * FROM SyncDb.SKUImage WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product))');
								$db->exec('CREATE TABLE ProductOption AS SELECT * FROM SyncDb.ProductOption WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
								$db->exec('CREATE TABLE ProductOptionValue AS SELECT * FROM SyncDb.ProductOptionValue WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
								$db->exec('CREATE TABLE ProductHTML AS SELECT * FROM SyncDb.ProductHTML WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
								$db->exec('CREATE TABLE Attribute AS SELECT * FROM SyncDb.Attribute');
								$db->exec('CREATE TABLE ProductAttributeValue AS SELECT * FROM SyncDb.ProductAttributeValue WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');

								if($db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM SyncDb.sqlite_master WHERE name = \'ProductDelete\' COLLATE NOCASE AND type = \'table\') THEN 1 ELSE 0 END')->fetchColumn())
									$db->exec('CREATE TABLE ProductDelete AS SELECT * FROM SyncDb.ProductDelete WHERE ExternalReference IN ('.implode(',', $productIds).')');
							}

							if($request->getQuery('orderid'))
							{
								$orderIds = Zend_Json::decode($request->getQuery('orderid'));
								if(!is_array($orderIds))
									$orderIds = array($orderIds);

								$orderIds = array_map('intval', $orderIds);

								$db->exec('CREATE TABLE [Order] AS SELECT * FROM SyncDb.[Order] WHERE ID IN ('.implode(',', $orderIds).')');
							}

							$db->exec('COMMIT TRANSACTION');
							$db->exec('VACUUM');

							$this->Send($tmpDb);

							unlink($tmpDb);
						}
						else
						{
							$this->Send($syncDb);
						}
					}
					else
					{
						//@codingStandardsIgnoreStart
						if(function_exists('http_response_code'))
							http_response_code(400);
						//@codingStandardsIgnoreEnd
						$response->setHttpResponseCode(400);
						$response->setRawHeader('HTTP/1.0 400 Security Error');
						$response->setRawHeader('Status: 400 Security Error');
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody('Security Error');
						$response->sendResponse();
					}
					die;

				case 'EXECUTE':

					if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						$indexer = Mage::getModel('index/process');
						$indexer->load('codistoebayindex', 'indexer_code')
									->changeStatus(Mage_Index_Model_Process::STATUS_RUNNING);

						$syncObject = Mage::getModel('codistosync/sync');

						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';
						if(file_exists($syncDb))
							unlink($syncDb);

						$syncObject->Sync($syncDb, $storeId);

						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody('done');
						$response->sendResponse();

						$indexer->changeStatus(Mage_Index_Model_Process::STATUS_PENDING);
					}
					else
					{
						//@codingStandardsIgnoreStart
						if(function_exists('http_response_code'))
							http_response_code(400);
						//@codingStandardsIgnoreEnd
						$response->setHttpResponseCode(400);
						$response->setRawHeader('HTTP/1.0 400 Security Error');
						$response->setRawHeader('Status: 400 Security Error');
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody('Security Error');
						$response->sendResponse();
					}
					die;


				case 'EXECUTECHUNK':

					if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						$indexer = Mage::getModel('index/process');
						$indexer->load('codistoebayindex', 'indexer_code')
									->changeStatus(Mage_Index_Model_Process::STATUS_RUNNING);

						$syncObject = Mage::getModel('codistosync/sync');

						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

						if($request->getPost('Init') == '1')
						{
							if(file_exists($syncDb))
								unlink($syncDb);
						}

						$timeout = $request->getQuery('timeout');
						if(!$timeout || !is_numeric($timeout))
							$timeout = $this->defaultSyncTimeout;

						$sleep = $request->getQuery('sleep');
						if(!$sleep || !is_numeric($sleep))
							$sleep = $this->defaultSleep;

						$startTime = microtime(true);

						for(;;)
						{
							$result = $syncObject->SyncChunk($syncDb, $storeId);

							if($result == 'complete')
							{
								$syncObject->SyncTax($syncDb, $storeId);
								$syncObject->SyncStores($syncDb, $storeId);

								$indexer->changeStatus(Mage_Index_Model_Process::STATUS_PENDING);
								break;
							}

							$now = microtime(true);

							if(($now - $startTime) > $timeout)
							{
								break;
							}

							usleep($sleep);
						}

						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody($result);
						$response->sendResponse();
					}
					else
					{
						//@codingStandardsIgnoreStart
						if(function_exists('http_response_code'))
							http_response_code(400);
						//@codingStandardsIgnoreEnd
						$response->setHttpResponseCode(400);
						$response->setRawHeader('HTTP/1.0 400 Security Error');
						$response->setRawHeader('Status: 400 Security Error');
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody('Security Error');
						$response->sendResponse();
					}
					die;

				case 'PULL':

					if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

						$ProductID = (int)$request->getPost('ProductID');
						$productIds = array($ProductID);

						$syncObject = Mage::getModel('codistosync/sync');

						$syncObject->UpdateProducts($syncDb, $productIds, $storeId);

						$tmpDb = tempnam(Mage::getBaseDir('var'), 'codisto-ebay-sync-');

						$db = new PDO('sqlite:' . $tmpDb);
						$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

						$db->exec('PRAGMA synchronous=0');
						$db->exec('PRAGMA temp_store=2');
						$db->exec('PRAGMA page_size=65536');
						$db->exec('PRAGMA encoding=\'UTF-8\'');
						$db->exec('PRAGMA cache_size=15000');
						$db->exec('PRAGMA soft_heap_limit=67108864');
						$db->exec('PRAGMA journal_mode=MEMORY');

						$db->exec('ATTACH DATABASE \''.$syncDb.'\' AS SyncDB');

						$db->exec('BEGIN EXCLUSIVE TRANSACTION');
						$db->exec('CREATE TABLE Product AS SELECT * FROM SyncDb.Product WHERE ExternalReference IN ('.implode(',', $productIds).') OR ExternalReference IN (SELECT ProductExternalReference FROM SKU WHERE ExternalReference IN ('.implode(',', $productIds).'))');
						$db->exec('CREATE TABLE ProductImage AS SELECT * FROM SyncDb.ProductImage WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
						$db->exec('CREATE TABLE CategoryProduct AS SELECT * FROM SyncDb.CategoryProduct WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
						$db->exec('CREATE TABLE SKU AS SELECT * FROM SyncDb.SKU WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
						$db->exec('CREATE TABLE SKUMatrix AS SELECT * FROM SyncDb.SKUMatrix WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product))');
						$db->exec('CREATE TABLE SKUImage AS SELECT * FROM SyncDb.SKUImage WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product))');
						$db->exec('CREATE TABLE ProductOption AS SELECT * FROM SyncDb.ProductOption WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
						$db->exec('CREATE TABLE ProductOptionValue AS SELECT * FROM SyncDb.ProductOptionValue WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
						$db->exec('CREATE TABLE ProductHTML AS SELECT * FROM SyncDb.ProductHTML WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
						$db->exec('CREATE TABLE Attribute AS SELECT * FROM SyncDb.Attribute');
						$db->exec('CREATE TABLE ProductAttributeValue AS SELECT * FROM SyncDb.ProductAttributeValue WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
						$db->exec('COMMIT TRANSACTION');
						$db->exec('VACUUM');

						$this->Send($tmpDb);

						unlink($tmpDb);
					}
					else
					{
						//@codingStandardsIgnoreStart
						if(function_exists('http_response_code'))
							http_response_code(400);
						//@codingStandardsIgnoreEnd
						$response->setHttpResponseCode(400);
						$response->setRawHeader('HTTP/1.0 400 Security Error');
						$response->setRawHeader('Status: 400 Security Error');
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody('Security Error');
						$response->sendResponse();
					}
					die;

				case 'TAX':

					if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						$syncObject = Mage::getModel('codistosync/sync');

						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

						$syncObject->SyncTax($syncDb, $storeId);

						$tmpDb = tempnam(Mage::getBaseDir('var'), 'codisto-ebay-sync-');

						$db = new PDO('sqlite:' . $tmpDb);
						$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

						$db->exec('PRAGMA synchronous=0');
						$db->exec('PRAGMA temp_store=2');
						$db->exec('PRAGMA page_size=65536');
						$db->exec('PRAGMA encoding=\'UTF-8\'');
						$db->exec('PRAGMA cache_size=15000');
						$db->exec('PRAGMA soft_heap_limit=67108864');
						$db->exec('PRAGMA journal_mode=MEMORY');

						$db->exec('ATTACH DATABASE \''.$syncDb.'\' AS SyncDB');

						$db->exec('BEGIN EXCLUSIVE TRANSACTION');
						$db->exec('CREATE TABLE TaxClass AS SELECT * FROM SyncDb.TaxClass');
						$db->exec('CREATE TABLE TaxCalculation AS SELECT * FROM SyncDb.TaxCalculation');
						$db->exec('CREATE TABLE TaxCalculationRule AS SELECT * FROM SyncDb.TaxCalculationRule');
						$db->exec('CREATE TABLE TaxCalculationRate AS SELECT * FROM SyncDb.TaxCalculationRate');
						$db->exec('COMMIT TRANSACTION');
						$db->exec('VACUUM');

						$this->Send($tmpDb);

						unlink($tmpDb);
					}
					else
					{
						//@codingStandardsIgnoreStart
						if(function_exists('http_response_code'))
							http_response_code(400);
						//@codingStandardsIgnoreEnd
						$response->setHttpResponseCode(400);
						$response->setRawHeader('HTTP/1.0 400 Security Error');
						$response->setRawHeader('Status: 400 Security Error');
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody('Security Error');
						$response->sendResponse();
					}
					die;

				case 'STOREVIEW':

					if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						$syncObject = Mage::getModel('codistosync/sync');

						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

						$syncObject->SyncStores($syncDb, $storeId);

						$tmpDb = tempnam(Mage::getBaseDir('var'), 'codisto-ebay-sync-');

						$db = new PDO('sqlite:' . $tmpDb);
						$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

						$db->exec('PRAGMA synchronous=0');
						$db->exec('PRAGMA temp_store=2');
						$db->exec('PRAGMA page_size=65536');
						$db->exec('PRAGMA encoding=\'UTF-8\'');
						$db->exec('PRAGMA cache_size=15000');
						$db->exec('PRAGMA soft_heap_limit=67108864');
						$db->exec('PRAGMA journal_mode=MEMORY');

						$db->exec('ATTACH DATABASE \''.$syncDb.'\' AS SyncDB');

						$db->exec('BEGIN EXCLUSIVE TRANSACTION');
						$db->exec('CREATE TABLE Store AS SELECT * FROM SyncDb.Store');
						$db->exec('COMMIT TRANSACTION');
						$db->exec('VACUUM');

						$this->Send($tmpDb);

						unlink($tmpDb);
					}
					else
					{
						//@codingStandardsIgnoreStart
						if(function_exists('http_response_code'))
							http_response_code(400);
						//@codingStandardsIgnoreEnd
						$response->setHttpResponseCode(400);
						$response->setRawHeader('HTTP/1.0 400 Security Error');
						$response->setRawHeader('Status: 400 Security Error');
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody('Security Error');
						$response->sendResponse();
					}
					die;

				case 'ORDERS':

					if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						$syncObject = Mage::getModel('codistosync/sync');

						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

						if($request->getQuery('orderid'))
						{
							$orders = Zend_Json::decode($request->getQuery('orderid'));
							if(!is_array($orders))
								$orders = array($orders);

							$syncObject->SyncOrders($syncDb, $orders, $storeId);
						}

						$tmpDb = tempnam(Mage::getBaseDir('var'), 'codisto-ebay-sync-');

						$db = new PDO('sqlite:' . $tmpDb);
						$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

						$db->exec('PRAGMA synchronous=0');
						$db->exec('PRAGMA temp_store=2');
						$db->exec('PRAGMA page_size=65536');
						$db->exec('PRAGMA encoding=\'UTF-8\'');
						$db->exec('PRAGMA cache_size=15000');
						$db->exec('PRAGMA soft_heap_limit=67108864');
						$db->exec('PRAGMA journal_mode=MEMORY');

						$db->exec('ATTACH DATABASE \''.$syncDb.'\' AS SyncDB');

						$db->exec('BEGIN EXCLUSIVE TRANSACTION');
						$db->exec('CREATE TABLE [Order] AS SELECT * FROM SyncDb.[Order]');
						$db->exec('COMMIT TRANSACTION');
						$db->exec('VACUUM');

						$this->Send($tmpDb);

						unlink($tmpDb);
					}
					else
					{
						//@codingStandardsIgnoreStart
						if(function_exists('http_response_code'))
							http_response_code(400);
						//@codingStandardsIgnoreEnd
						$response->setHttpResponseCode(400);
						$response->setRawHeader('HTTP/1.0 400 Security Error');
						$response->setRawHeader('Status: 400 Security Error');
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody('Security Error');
						$response->sendResponse();
					}
					die;

				case 'TEMPLATE':

					if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						if($request->isGet())
						{
							$templateDb = Mage::getBaseDir('var') . '/codisto-ebay-template.db';

							if($request->getQuery('markreceived'))
							{
								try
								{
									$db = new PDO('sqlite:' . $templateDb);
									$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

									$update = $db->prepare('UPDATE File SET LastModified = ? WHERE Name = ?');

									$files = $db->query('SELECT Name FROM File WHERE Changed != 0');
									$files->execute();

									$db->exec('BEGIN EXCLUSIVE TRANSACTION');

									while($row = $files->fetch())
									{
										$stat = stat(Mage::getBaseDir('design').'/ebay/'.$row['Name']);

										$lastModified = strftime('%Y-%m-%d %H:%M:%S', $stat['mtime']);

										$update->bindParam(1, $lastModified);
										$update->bindParam(2, $row['Name']);
										$update->execute();
									}

									$db->exec('UPDATE File SET Changed = 0');
									$db->exec('COMMIT TRANSACTION');
									$db = null;

									$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
									$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
									$response->setHeader('Pragma', 'no-cache', true);
									$response->setBody(Zend_Json::encode(array( 'ack' => 'ok' )));
									$response->sendResponse();
								}
								catch(Exception $e)
								{
									//@codingStandardsIgnoreStart
									if(function_exists('http_response_code'))
										http_response_code(500);
									//@codingStandardsIgnoreEnd
									$response->setHttpResponseCode(500);
									$response->setRawHeader('HTTP/1.0 500 Internal Server Error');
									$response->setRawHeader('Status: 500 Internal Server Error');
									$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
									$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
									$response->setHeader('Pragma', 'no-cache', true);
									$response->setBody(Zend_Json::encode(array( 'ack' => 'failed', 'message' => $e->getMessage() )));
									$response->sendResponse();
								}
							}
							else
							{
								$syncObject = Mage::getModel('codistosync/sync');

								$syncObject->TemplateRead($templateDb);

								$tmpDb = tempnam(Mage::getBaseDir('var'), 'codisto-ebay-template-');

								$db = new PDO('sqlite:' . $tmpDb);
								$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
								$db->exec('PRAGMA synchronous=0');
								$db->exec('PRAGMA temp_store=2');
								$db->exec('PRAGMA page_size=512');
								$db->exec('PRAGMA encoding=\'UTF-8\'');
								$db->exec('PRAGMA cache_size=15000');
								$db->exec('PRAGMA soft_heap_limit=67108864');
								$db->exec('PRAGMA journal_mode=OFF');
								$db->exec('ATTACH DATABASE \''.$templateDb.'\' AS Source');
								$db->exec('CREATE TABLE File AS SELECT * FROM Source.File WHERE Changed != 0');
								$db->exec('DETACH DATABASE Source');
								$db->exec('VACUUM');

								$fileCountStmt = $db->query('SELECT COUNT(*) AS fileCount FROM File');
								$fileCountStmt->execute();
								$fileCountRow = $fileCountStmt->fetch();
								$fileCount = $fileCountRow['fileCount'];
								$db = null;

								if($fileCount == 0)
								{
									//@codingStandardsIgnoreStart
									if(function_exists('http_response_code'))
										http_response_code(204);
									//@codingStandardsIgnoreEnd
									$response->setHttpResponseCode(204);
									$response->setRawHeader('HTTP/1.0 204 No Content');
									$response->setRawHeader('Status: 204 No Content');
									$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
									$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
									$response->setHeader('Pragma', 'no-cache', true);
									$response->setBody('');
									$response->sendResponse();
								}
								else
								{
									$this->Send($tmpDb);
								}

								unlink($tmpDb);
							}
						}
						else if($request->isPost() || $request->isPut())
						{
							$tmpDb = tempnam(Mage::getBaseDir('var'), 'codisto-ebay-template-');

							file_put_contents($tmpDb, $request->getRawBody());

							$syncObject = Mage::getModel('codistosync/sync');

							$syncObject->TemplateWrite($tmpDb);

							unlink($tmpDb);

							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody(Zend_Json::encode(array( 'ack' => 'ok' )));
							$response->sendResponse();
						}
					}
					else
					{
						//@codingStandardsIgnoreStart
						if(function_exists('http_response_code'))
							http_response_code(400);
						//@codingStandardsIgnoreEnd
						$response->setHttpResponseCode(400);
						$response->setRawHeader('HTTP/1.0 400 Security Error');
						$response->setRawHeader('Status: 400 Security Error');
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody('Security Error');
						$response->sendResponse();
					}
					die;

				default:

					$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
					$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
					$response->setHeader('Pragma', 'no-cache', true);
					$response->setBody('No Action');
					$response->sendResponse();
			}
		}
	}

	public function testHashAction()
	{
		$request = $this->getRequest();
		$response = $this->getResponse();
		$server = $request->getServer();

		$storeId = $request->getQuery('storeid') == null ? 0 : (int)$request->getQuery('storeid');

		if(!$this->getConfig($storeId))
		{
			//@codingStandardsIgnoreStart
			if(function_exists('http_response_code'))
				http_response_code(500);
			//@codingStandardsIgnoreEnd
			$response->setHttpResponseCode(500);
			$response->setRawHeader('HTTP/1.0 500 Config Error');
			$response->setRawHeader('Status: 500 Config Error');
			$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
			$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
			$response->setHeader('Pragma', 'no-cache', true);
			$response->setBody('Config Error');
			return;
		}

		$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
		$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
		$response->setHeader('Pragma', 'no-cache', true);

		if($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
		{
			$version = (string)Mage::getConfig()->getModuleConfig('Codisto_Sync')->version;
			$response->setHeader('X-Codisto-Version', $version, true);

			$response->setBody('OK');
		}
		else
		{
			//@codingStandardsIgnoreStart
			if(function_exists('http_response_code'))
				http_response_code(400);
			//@codingStandardsIgnoreEnd
			$response->setHttpResponseCode(400);
			$response->setRawHeader('HTTP/1.0 400 Security Error');
			$response->setRawHeader('Status: 400 Security Error');
			$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
			$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
			$response->setHeader('Pragma', 'no-cache', true);
			$response->setBody('Security Error');
		}

	}

	public function checkPluginAction()
	{ // End Point: index.php/codisto-sync/sync/checkPlugin

		$request = $this->getRequest();
		$response = $this->getResponse();
		$server = $request->getServer();

		$storeId = $request->getQuery('storeid') == null ? 0 : (int)$request->getQuery('storeid');

		if(!$this->getConfig($storeId))
		{
			//@codingStandardsIgnoreStart
			if(function_exists('http_response_code'))
				http_response_code(500);
			//@codingStandardsIgnoreEnd
			$response->setHttpResponseCode(500);
			$response->setRawHeader('HTTP/1.0 500 Config Error');
			$response->setRawHeader('Status: 500 Config Error');
			$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
			$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
			$response->setHeader('Pragma', 'no-cache', true);
			$response->setBody('Config Error');
			return;
		}

		$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
		$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
		$response->setHeader('Pragma', 'no-cache', true);

		$response->setBody('SUCCESS');
		$response->sendResponse();
	}

	public function resetPluginAction()
	{ // End Point index.php/codisto-sync/sync/resetPlugin

		$request = $this->getRequest();
		$response = $this->getResponse();
		$server = $request->getServer();

		$storeId = $request->getQuery('storeid') == null ? 0 : (int)$request->getQuery('storeid');

		if(!$this->getConfig($storeId))
		{
			//@codingStandardsIgnoreStart
			if(function_exists('http_response_code'))
				http_response_code(500);
			//@codingStandardsIgnoreEnd
			$response->setHttpResponseCode(500);
			$response->setRawHeader('HTTP/1.0 500 Security Error');
			$response->setRawHeader('Status: 500 Security Error');
			$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
			$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
			$response->setHeader('Pragma', 'no-cache', true);
			$response->setBody('Config Error');
			return;
		}

		if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
		{
			$config = Mage::getConfig();

			if($storeId == 0)
			{
				$config->saveConfig('codisto/merchantid', null);
				$config->saveConfig('codisto/hostkey', null);
			}
			else
			{
				$store = Mage::app()->getStore($storeId);

				$config->saveConfig('stores/'.$store->getCode().'/codisto/merchantid', null);
				$config->saveConfig('stores/'.$store->getCode().'/codisto/hostkey', null);
			}

			$config->cleanCache();

			Mage::app()->removeCache('config_store_data');
			Mage::app()->getCacheInstance()->cleanType('config');
			Mage::app()->reinitStores();

			$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
			$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
			$response->setHeader('Pragma', 'no-cache', true);

			$response->setBody('SUCCESS');
		}
		else
		{

			$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
			$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
			$response->setHeader('Pragma', 'no-cache', true);

			$response->setBody('Invalid Request');
		}
	}

	private function Send($syncDb)
	{
		header('Cache-Control: no-cache, must-revalidate'); //HTTP 1.1
		header('Pragma: no-cache'); //HTTP 1.0
		header('Expires: Thu, 01 Jan 1970 00:00:00 GMT'); // Date in the past
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename=' . basename($syncDb));
		header('Content-Length: ' . filesize($syncDb));

		if(ob_get_level() > 0)
			ob_end_clean();

		flush();

		readfile($syncDb);
	}


}
