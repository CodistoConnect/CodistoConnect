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
 * @category	Codisto
 * @package	 	Codisto_Sync
 * @copyright   Copyright (c) 2015 On Technology Pty. Ltd. (http://codisto.com/)
 * @license	 	http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Codisto_Sync_SyncController extends Mage_Core_Controller_Front_Action
{
	private $defaultConfigurableCount = 6;
	private $defaultSimpleCount = 250;

	public function preDispatch()
	{
		Mage::app()->loadArea(Mage_Core_Model_App_Area::AREA_ADMINHTML);

		$this->setFlag('', self::FLAG_NO_START_SESSION, 1);
		$this->setFlag('', self::FLAG_NO_PRE_DISPATCH, 1);
		$this->setFlag('', self::FLAG_NO_POST_DISPATCH, 1);

		return $this;
	}

	public function indexAction()
	{

		set_time_limit(0);

		@ini_set('zlib.output_compression', 'Off');
		@ini_set('output_buffering', 'Off');
		@ini_set('output_handler', '');

		ignore_user_abort(true);

		$response = $this->getResponse();
		$request = $this->getRequest();
		$request->setDispatched(true);
		$server = $request->getServer();

		$helper = Mage::helper('codistosync');

		$storeId = $request->getQuery('storeid') == null ? 0 : (int)$request->getQuery('storeid');

		if($storeId == 0)
		{
			// jump the storeid to first non admin store
			$stores = Mage::getModel('core/store')->getCollection()
										->addFieldToFilter('is_active', array('neq' => 0))
										->addFieldToFilter('store_id', array('gt' => 0))
										->setOrder('store_id', 'ASC');

			if($stores->getSize() == 1)
			{
				$stores->setPageSize(1)->setCurPage(1);
				$firstStore = $stores->getFirstItem();
				if(is_object($firstStore) && $firstStore->getId())
					$storeId = $firstStore->getId();
			}
		}

		if(!$helper->getConfig($storeId))
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
			$response->setBody('Config Error');
			return;
		}

		$store = Mage::app()->getStore($storeId);

		Mage::app()->setCurrentStore($store);

		if(isset($server['HTTP_X_SYNC']) &&
			isset($server['HTTP_X_ACTION']))
		{
			switch ( $server['HTTP_X_ACTION'] ) {

				case 'GET':

					if (isset($server['HTTP_X_NONCE'], $server['HTTP_X_HASH']) &&
						$helper->checkHash($response, Mage::getStoreConfig('codisto/hostkey', $storeId), $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						try
						{
							if($request->getQuery('first'))
								$syncDb = $helper->getSyncPath('sync-first-'.$storeId.'.db');
							else
								$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

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

								$tmpDb = $helper->getSyncPathTemp('sync');

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
									$db->exec('CREATE TABLE SKULink AS SELECT * FROM SyncDb.SKULink WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
									$db->exec('CREATE TABLE SKUMatrix AS SELECT * FROM SyncDb.SKUMatrix WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
									$db->exec('CREATE TABLE SKUImage AS SELECT * FROM SyncDb.SKUImage WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU)');
									$db->exec('CREATE TABLE ProductOptionValue AS SELECT DISTINCT * FROM SyncDb.ProductOptionValue');
									$db->exec('CREATE TABLE ProductHTML AS SELECT * FROM SyncDb.ProductHTML WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
									$db->exec('CREATE TABLE Attribute AS SELECT * FROM SyncDb.Attribute');
									$db->exec('CREATE TABLE AttributeGroup AS SELECT * FROM SyncDb.AttributeGroup');
									$db->exec('CREATE TABLE AttributeGroupMap AS SELECT * FROM SyncDb.AttributeGroupMap');
									$db->exec('CREATE TABLE ProductAttributeValue AS SELECT * FROM SyncDb.ProductAttributeValue WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
									$db->exec('CREATE TABLE ProductQuestion AS SELECT * FROM SyncDb.ProductQuestion WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
									$db->exec('CREATE TABLE ProductQuestionAnswer AS SELECT * FROM SyncDb.ProductQuestionAnswer WHERE ProductQuestionExternalReference IN (SELECT ExternalReference FROM ProductQuestion)');

									$qry = $db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM SyncDb.sqlite_master WHERE lower(name) = \'productdelete\' AND type = \'table\') THEN 1 ELSE 0 END');

									if($qry->fetchColumn())
										$db->exec('CREATE TABLE ProductDelete AS SELECT * FROM SyncDb.ProductDelete WHERE ExternalReference IN ('.implode(',', $productIds).')');

									$qry->closeCursor();
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
								$db->exec('DETACH DATABASE SyncDB');
								$db->exec('VACUUM');

								$this->Send($tmpDb);

								unlink($tmpDb);
							}
							else
							{
								$this->Send($syncDb);
							}
						}
						catch(Exception $e)
						{
							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(500);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(500);
							$response->setRawHeader('HTTP/1.0 500 Sync Exception');
							$response->setRawHeader('Status: 500 Sync Exception');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody('Exception: '.$e->getMessage().' on line: '.$e->getLine().' in file: '.$e->getFile());
							$response->sendResponse();
						}
					}
					else
					{
						$response->clearAllHeaders();
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

					if (isset($server['HTTP_X_NONCE'], $server['HTTP_X_HASH']) &&
						$helper->checkHash($response, Mage::getStoreConfig('codisto/hostkey', $storeId), $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						try
						{
							$indexer = Mage::getModel('index/process');

							try
							{
								$indexer->load('codistoebayindex', 'indexer_code')
											->changeStatus(Mage_Index_Model_Process::STATUS_RUNNING);
							}
							catch(Exception $e)
							{

							}

							$syncObject = Mage::getModel('codistosync/sync');

							$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

							if($request->getPost('Init') == '1')
							{
								if(preg_match('/\/codisto\//', $syncDb))
								{
									@array_map('@unlink', glob( Mage::getBaseDir('var').'/codisto-*') );
								}

								if($helper->canSyncIncrementally($syncDb))
								{
									//@codingStandardsIgnoreStart
									if(function_exists('http_response_code'))
										http_response_code(200);
									//@codingStandardsIgnoreEnd
									$response->setHttpResponseCode(200);
									$response->setRawHeader('HTTP/1.0 200 OK');
									$response->setRawHeader('Status: 200 OK');
									$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
									$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
									$response->setHeader('Pragma', 'no-cache', true);
									$response->setBody('incremental');
									$response->sendResponse();
									die;
								}

								if(file_exists($syncDb))
									unlink($syncDb);
							}

							$configurableCount = (int)$request->getQuery('configurablecount');
							if(!$configurableCount || !is_numeric($configurableCount))
								$configurableCount = $this->defaultConfigurableCount;

							$simpleCount = (int)$request->getQuery('simplecount');
							if(!$simpleCount || !is_numeric($simpleCount))
								$simpleCount = $this->defaultSimpleCount;

							$result = $syncObject->SyncChunk($syncDb, $simpleCount, $configurableCount, $storeId, false);

							if($result == 'complete')
							{
								$syncObject->SyncTax($syncDb, $storeId);
								$syncObject->SyncStaticBlocks($syncDb, $storeId);
								$syncObject->SyncStores($syncDb, $storeId);

								for($Retry = 0; ; $Retry++)
								{
									try
									{
										$indexer->changeStatus(Mage_Index_Model_Process::STATUS_PENDING);
										break;
									}
									catch(Exception $e)
									{
										if($Retry >= 3)
											break;

										usleep(500000);
										continue;
									}
								}
							}

							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(200);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(200);
							$response->setRawHeader('HTTP/1.0 200 OK');
							$response->setRawHeader('Status: 200 OK');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody($result);
							$response->sendResponse();
						}
						catch(Exception $e)
						{
							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(500);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(500);
							$response->setRawHeader('HTTP/1.0 500 Sync Exception');
							$response->setRawHeader('Status: 500 Sync Exception');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody('Exception: '.$e->getMessage().' on line: '.$e->getLine().' in file: '.$e->getFile().' stack trace: '.$e->getTraceAsString());
							$response->sendResponse();
						}
					}
					else
					{
						$response->clearAllHeaders();
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

				case 'PRODUCTCOUNT':

					if (isset($server['HTTP_X_NONCE'], $server['HTTP_X_HASH']) &&
						$helper->checkHash($response, Mage::getStoreConfig('codisto/hostkey', $storeId), $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						$syncObject = Mage::getModel('codistosync/sync');
						$totals = $syncObject->ProductTotals($storeId);

						$response->clearAllHeaders();
						//@codingStandardsIgnoreStart
						if(function_exists('http_response_code'))
							http_response_code(200);
						//@codingStandardsIgnoreEnd
						$response->setHttpResponseCode(200);
						$response->setRawHeader('HTTP/1.0 400 Security Error');
						$response->setRawHeader('Status: 400 Security Error');
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody(Zend_Json::encode($totals));
						$response->sendResponse();
					}
					else
					{
						$response->clearAllHeaders();
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

				case 'EXECUTEFIRST':

					if (isset($server['HTTP_X_NONCE'], $server['HTTP_X_HASH']) &&
						$helper->checkHash($response, Mage::getStoreConfig('codisto/hostkey', $storeId), $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						try
						{
							$indexer = Mage::getModel('index/process');

							try
							{
								$indexer->load('codistoebayindex', 'indexer_code')
											->changeStatus(Mage_Index_Model_Process::STATUS_RUNNING);
							}
							catch(Exception $e)
							{

							}

							$syncObject = Mage::getModel('codistosync/sync');

							$syncDb = $helper->getSyncPath('sync-first-'.$storeId.'.db');

							if(file_exists($syncDb))
								unlink($syncDb);

							$configurableCount = (int)$request->getQuery('configurablecount');
							if(!$configurableCount || !is_numeric($configurableCount))
								$configurableCount = $this->defaultConfigurableCount;


							$simpleCount = (int)$request->getQuery('simplecount');
							if(!$simpleCount || !is_numeric($simpleCount))
								$simpleCount = $this->defaultSimpleCount;


							$result = $syncObject->SyncChunk($syncDb, 0, $configurableCount, $storeId, true);
							$result = $syncObject->SyncChunk($syncDb, $simpleCount, 0, $storeId, true);

							if($result == 'complete')
							{
								$syncObject->SyncTax($syncDb, $storeId);
								$syncObject->SyncStores($syncDb, $storeId);

								for($Retry = 0; ; $Retry++)
								{
									try
									{
										$indexer->changeStatus(Mage_Index_Model_Process::STATUS_PENDING);
										break;
									}
									catch(Exception $e)
									{
										if($Retry >= 3)
											break;

										usleep(500000);
										continue;
									}
								}

							} else {

								throw new Exception('First page execution failed');

							}

							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(200);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(200);
							$response->setRawHeader('HTTP/1.0 200 OK');
							$response->setRawHeader('Status: 200 OK');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody($result);
							$response->sendResponse();
						}
						catch(Exception $e)
						{
							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(500);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(500);
							$response->setRawHeader('HTTP/1.0 500 Sync Exception');
							$response->setRawHeader('Status: 500 Sync Exception');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody('Exception: '.$e->getMessage().' on line: '.$e->getLine().' in file: '.$e->getFile());
							$response->sendResponse();
						}
					}
					else
					{
						$response->clearAllHeaders();
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

					if (isset($server['HTTP_X_NONCE'], $server['HTTP_X_HASH']) &&
						$helper->checkHash($response, Mage::getStoreConfig('codisto/hostkey', $storeId), $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						try
						{
							$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

							$ProductID = (int)$request->getPost('ProductID');
							$productIds = array($ProductID);

							$syncObject = Mage::getModel('codistosync/sync');

							$syncObject->UpdateProducts($syncDb, $productIds, $storeId);

							$tmpDb = $helper->getSyncPathTemp('sync');

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
							$db->exec('CREATE TABLE SKULink AS SELECT * FROM SyncDb.SKULink WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
							$db->exec('CREATE TABLE SKUMatrix AS SELECT * FROM SyncDb.SKUMatrix WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
							$db->exec('CREATE TABLE SKUImage AS SELECT * FROM SyncDb.SKUImage WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU)');
							$db->exec('CREATE TABLE ProductOptionValue AS SELECT * FROM SyncDb.ProductOptionValue WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
							$db->exec('CREATE TABLE ProductHTML AS SELECT * FROM SyncDb.ProductHTML WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
							$db->exec('CREATE TABLE Attribute AS SELECT * FROM SyncDb.Attribute');
							$db->exec('CREATE TABLE AttributeGroup AS SELECT * FROM SyncDb.AttributeGroup');
							$db->exec('CREATE TABLE AttributeGroupMap AS SELECT * FROM SyncDb.AttributeGroupMap');
							$db->exec('CREATE TABLE ProductAttributeValue AS SELECT * FROM SyncDb.ProductAttributeValue WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
							$db->exec('CREATE TABLE ProductQuestion AS SELECT * FROM SyncDb.ProductQuestion WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
							$db->exec('CREATE TABLE ProductQuestionAnswer AS SELECT * FROM SyncDb.ProductQuestionAnswer WHERE ProductQuestionExternalReference IN (SELECT ExternalReference FROM ProductQuestion)');
							$db->exec('COMMIT TRANSACTION');
							$db->exec('DETACH DATABASE SyncDB');
							$db->exec('VACUUM');

							$this->Send($tmpDb);

							unlink($tmpDb);
						}
						catch(Exception $e)
						{
							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(500);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(500);
							$response->setRawHeader('HTTP/1.0 500 Sync Exception');
							$response->setRawHeader('Status: 500 Sync Exception');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody('Exception: '.$e->getMessage().' on line: '.$e->getLine().' in file: '.$e->getFile());
							$response->sendResponse();
						}
					}
					else
					{
						$response->clearAllHeaders();
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

					if (isset($server['HTTP_X_NONCE'], $server['HTTP_X_HASH']) &&
						$helper->checkHash($response, Mage::getStoreConfig('codisto/hostkey', $storeId), $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						try
						{
							$syncObject = Mage::getModel('codistosync/sync');

							$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

							$syncObject->SyncTax($syncDb, $storeId);

							$tmpDb = $helper->getSyncPathTemp('sync');

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
						catch(Exception $e)
						{
							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(500);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(500);
							$response->setRawHeader('HTTP/1.0 500 Sync Exception');
							$response->setRawHeader('Status: 500 Sync Exception');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody('Exception: '.$e->getMessage().' on line: '.$e->getLine().' in file: '.$e->getFile());
							$response->sendResponse();
						}
					}
					else
					{
						$response->clearAllHeaders();
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

					if (isset($server['HTTP_X_NONCE'], $server['HTTP_X_HASH']) &&
						$helper->checkHash($response, Mage::getStoreConfig('codisto/hostkey', $storeId), $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						try
						{
							$syncObject = Mage::getModel('codistosync/sync');

							$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

							$syncObject->SyncStores($syncDb, $storeId);

							$tmpDb = $helper->getSyncPathTemp('sync');

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
						catch(Exception $e)
						{
							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(500);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(500);
							$response->setRawHeader('HTTP/1.0 500 Sync Exception');
							$response->setRawHeader('Status: 500 Sync Exception');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody('Exception: '.$e->getMessage().' on line: '.$e->getLine().' in file: '.$e->getFile());
							$response->sendResponse();
						}
					}
					else
					{
						$response->clearAllHeaders();
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

					if (isset($server['HTTP_X_NONCE'], $server['HTTP_X_HASH']) &&
						$helper->checkHash($response, Mage::getStoreConfig('codisto/hostkey', $storeId), $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						try
						{
							$syncObject = Mage::getModel('codistosync/sync');

							$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

							if($request->getQuery('orderid'))
							{
								$orders = Zend_Json::decode($request->getQuery('orderid'));
								if(!is_array($orders))
									$orders = array($orders);

								$syncObject->SyncOrders($syncDb, $orders, $storeId);
							}

							$tmpDb = $helper->getSyncPathTemp('sync');

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
						catch(Exception $e)
						{
							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(500);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(500);
							$response->setRawHeader('HTTP/1.0 500 Sync Exception');
							$response->setRawHeader('Status: 500 Sync Exception');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody('Exception: '.$e->getMessage().' on line: '.$e->getLine().' in file: '.$e->getFile());
							$response->sendResponse();
						}
					}
					else
					{
						$response->clearAllHeaders();
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

					if (isset($server['HTTP_X_NONCE'], $server['HTTP_X_HASH']) &&
						$helper->checkHash($response, Mage::getStoreConfig('codisto/hostkey', $storeId), $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						try
						{
							if($request->isGet())
							{
								$merchantid = (int)$request->getQuery('merchantid');

								$templateDb = $helper->getSyncPath('template-'.$merchantid.'.db');

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

										$files->closeCursor();

										$db->exec('UPDATE File SET Changed = 0');
										$db->exec('COMMIT TRANSACTION');
										$db = null;

										$response->clearAllHeaders();
										//@codingStandardsIgnoreStart
										if(function_exists('http_response_code'))
											http_response_code(200);
										//@codingStandardsIgnoreEnd
										$response->setHttpResponseCode(200);
										$response->setRawHeader('HTTP/1.0 200 OK');
										$response->setRawHeader('Status: 200 OK');
										$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
										$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
										$response->setHeader('Pragma', 'no-cache', true);
										$response->setBody(Zend_Json::encode(array( 'ack' => 'ok' )));
										$response->sendResponse();
									}
									catch(Exception $e)
									{
										$response->clearAllHeaders();
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

									$tmpDb = $helper->getSyncPathTemp('template');

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

									$fileCountStmt->closeCursor();

									if($fileCount == 0)
									{
										$response->clearAllHeaders();
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
								$tmpDb = $helper->getSyncPathTemp('template');

								file_put_contents($tmpDb, $request->getRawBody());

								$syncObject = Mage::getModel('codistosync/sync');

								$syncObject->TemplateWrite($tmpDb);

								unlink($tmpDb);

								$response->clearAllHeaders();
								//@codingStandardsIgnoreStart
								if(function_exists('http_response_code'))
									http_response_code(200);
								//@codingStandardsIgnoreEnd
								$response->setHttpResponseCode(200);
								$response->setRawHeader('HTTP/1.0 200 OK');
								$response->setRawHeader('Status: 200 OK');
								$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
								$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
								$response->setHeader('Pragma', 'no-cache', true);
								$response->setBody(Zend_Json::encode(array( 'ack' => 'ok' )));
								$response->sendResponse();
							}
						}
						catch(Exception $e)
						{
							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(500);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(500);
							$response->setRawHeader('HTTP/1.0 500 Sync Exception');
							$response->setRawHeader('Status: 500 Sync Exception');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody('Exception: '.$e->getMessage().' on line: '.$e->getLine().' in file: '.$e->getFile());
							$response->sendResponse();
						}
					}
					else
					{
						$response->clearAllHeaders();
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

					$response->clearAllHeaders();
					//@codingStandardsIgnoreStart
					if(function_exists('http_response_code'))
						http_response_code(400);
					//@codingStandardsIgnoreEnd
					$response->setHttpResponseCode(400);
					$response->setRawHeader('HTTP/1.0 400 Bad Request No Action');
					$response->setRawHeader('Status: 400 Bad Request No Action');
					$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
					$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
					$response->setHeader('Pragma', 'no-cache', true);
					$response->setBody('No Action');
					$response->sendResponse();
					die;
			}
		}


	}

	public function testHashAction()
	{
		$request = $this->getRequest();
		$response = $this->getResponse();
		$server = $request->getServer();

		$helper = Mage::helper('codistosync');

		$storeId = $request->getQuery('storeid') == null ? 0 : (int)$request->getQuery('storeid');

		if(!$helper->getConfig($storeId))
		{
			$response->clearAllHeaders();
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

		if(isset($server['HTTP_X_NONCE'], $server['HTTP_X_HASH']) &&
			$helper->checkHash($response, Mage::getStoreConfig('codisto/hostkey', $storeId), $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
		{
			$extSyncFailed = $helper->getSyncPath('external-sync-failed');
			if(file_exists($extSyncFailed))
				unlink($extSyncFailed);

			$extTestFailed = $helper->getSyncPath('external-test-failed');
			if(file_exists($extTestFailed))
				unlink($extTestFailed);

			$version = $helper->getCodistoVersion();

			$response->clearAllHeaders();
			//@codingStandardsIgnoreStart
			if(function_exists('http_response_code'))
				http_response_code(200);
			//@codingStandardsIgnoreEnd
			$response->setHttpResponseCode(200);
			$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
			$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
			$response->setHeader('Pragma', 'no-cache', true);
			$response->setHeader('X-Codisto-Version', $version, true);
			$response->setBody('OK');
		}
		else
		{
			$response->clearAllHeaders();
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

		if(!$helper->getConfig($storeId))
		{
			$response->clearAllHeaders();
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

		// TODO: read store view state and post to api.codisto.com

		$response->clearAllHeaders();
		//@codingStandardsIgnoreStart
		if(function_exists('http_response_code'))
			http_response_code(200);
		//@codingStandardsIgnoreEnd
		$response->setHttpResponseCode(200);
		$response->setRawHeader('HTTP/1.0 200 OK');
		$response->setRawHeader('Status: 200 OK');
		$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
		$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
		$response->setHeader('Pragma', 'no-cache', true);
		$response->setBody('SUCCESS');
		$response->sendResponse();
	}

	private function Send($syncDb)
	{
		ignore_user_abort(false);

		//@codingStandardsIgnoreStart
		if(function_exists('http_response_code'))
			http_response_code(200);
		//@codingStandardsIgnoreEnd
		header('HTTP/1.0 200 OK');
		header('Status: 200 OK');
		header('Cache-Control: no-cache, must-revalidate'); //HTTP 1.1
		header('Pragma: no-cache'); //HTTP 1.0
		header('Expires: Thu, 01 Jan 1970 00:00:00 GMT'); // Date in the past
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename=' . basename($syncDb));

		if(strtolower(ini_get('zlib.output_compression')) == 'off')
		{
			header('Content-Length: ' . filesize($syncDb));
		}

		while(ob_get_level() > 0)
		{
			if(!@ob_end_clean())
				break;
		}

		flush();

		readfile($syncDb);
	}


}
