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
			$this->sendConfigError($response);
			return;
		}

		$store = Mage::app()->getStore($storeId);

		Mage::app()->setCurrentStore($store);

		if(isset($server['HTTP_X_SYNC']) &&
			isset($server['HTTP_X_ACTION']))
		{
			switch ( $server['HTTP_X_ACTION'] ) {

				case 'GET':

					if($this->checkHash($helper, $server, $storeId))
					{
						try
						{
							if($request->getQuery('first'))
								$syncDb = $helper->getSyncPath('sync-first-'.$storeId.'.db');
							else
								$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

							if($request->getQuery('productid') ||
								$request->getQuery('categoryid') ||
								$request->getQuery('orderid'))
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

								$helper->prepareSqliteDatabase($db);

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
									$db->exec('CREATE UNIQUE INDEX IX_Product_ExternalReference ON Product(ExternalReference)');
									$db->exec('CREATE TABLE ProductImage AS SELECT * FROM SyncDb.ProductImage WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
									$db->exec('CREATE TABLE CategoryProduct AS SELECT * FROM SyncDb.CategoryProduct WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
									$db->exec('CREATE TABLE SKU AS SELECT * FROM SyncDb.SKU WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
									$db->exec('CREATE TABLE SKULink AS SELECT * FROM SyncDb.SKULink WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
									$db->exec('CREATE TABLE SKUMatrix AS SELECT * FROM SyncDb.SKUMatrix WHERE ProductExternalReference IN (SELECT ExternalReference FROM Product)');
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

									$db->exec('DROP INDEX IX_Product_ExternalReference');
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

								$this->sendFile($tmpDb);

								unlink($tmpDb);
							}
							else
							{
								$sendFullDb = true;

								if(!$request->getQuery('first') &&
									is_string($request->getQuery('incremental')))
								{
									$tmpDb = $helper->getSyncPathTemp('sync');

									$db = new PDO('sqlite:' . $tmpDb);

									$helper->prepareSqliteDatabase($db);

									$db->exec('ATTACH DATABASE \''.$syncDb.'\' AS SyncDB');

									$db->exec('BEGIN EXCLUSIVE TRANSACTION');

									$qry = $db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM SyncDb.sqlite_master WHERE type COLLATE NOCASE = \'TABLE\' AND name = \'ProductChange\') THEN -1 ELSE 0 END');
									$productChange = $qry->fetchColumn();
									$qry->closeCursor();
									if($productChange)
									{
										$qry = $db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM SyncDb.ProductChange) THEN -1 ELSE 0 END');
										$productsAvailable = $qry->fetchColumn();
										$qry->closeCursor();

										if($productsAvailable)
										{
											$db->exec('CREATE TABLE Product AS SELECT * FROM SyncDb.Product WHERE ExternalReference IN (SELECT ExternalReference FROM SyncDb.ProductChange)');
											$db->exec('CREATE TABLE ProductImage AS SELECT * FROM SyncDb.ProductImage WHERE ProductExternalReference IN (SELECT ExternalReference FROM SyncDb.ProductChange)');
											$db->exec('CREATE TABLE CategoryProduct AS SELECT * FROM SyncDb.CategoryProduct WHERE ProductExternalReference IN (SELECT ExternalReference FROM SyncDb.ProductChange)');
											$db->exec('CREATE TABLE SKU AS SELECT * FROM SyncDb.SKU WHERE ProductExternalReference IN (SELECT ExternalReference FROM SyncDb.ProductChange)');
											$db->exec('CREATE TABLE SKULink AS SELECT * FROM SyncDb.SKULink WHERE ProductExternalReference IN (SELECT ExternalReference FROM SyncDb.ProductChange)');
											$db->exec('CREATE TABLE SKUMatrix AS SELECT * FROM SyncDb.SKUMatrix WHERE ProductExternalReference IN (SELECT ExternalReference FROM SyncDb.ProductChange)');
											$db->exec('CREATE TABLE ProductOptionValue AS SELECT DISTINCT * FROM SyncDb.ProductOptionValue');
											$db->exec('CREATE TABLE ProductHTML AS SELECT * FROM SyncDb.ProductHTML WHERE ProductExternalReference IN (SELECT ExternalReference FROM SyncDb.ProductChange)');
											$db->exec('CREATE TABLE Attribute AS SELECT * FROM SyncDb.Attribute');
											$db->exec('CREATE TABLE AttributeGroup AS SELECT * FROM SyncDb.AttributeGroup');
											$db->exec('CREATE TABLE AttributeGroupMap AS SELECT * FROM SyncDb.AttributeGroupMap');
											$db->exec('CREATE TABLE ProductAttributeValue AS SELECT * FROM SyncDb.ProductAttributeValue WHERE ProductExternalReference IN (SELECT ExternalReference FROM SyncDb.ProductChange)');
											$db->exec('CREATE TABLE ProductQuestion AS SELECT * FROM SyncDb.ProductQuestion WHERE ProductExternalReference IN (SELECT ExternalReference FROM SyncDb.ProductChange)');
											$db->exec('CREATE TABLE ProductQuestionAnswer AS SELECT * FROM SyncDb.ProductQuestionAnswer WHERE ProductQuestionExternalReference IN (SELECT ExternalReference FROM ProductQuestion)');
											$db->exec('CREATE TABLE ProductChange AS SELECT * FROM SyncDb.ProductChange');
										}
									}

									$qry = $db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM SyncDb.sqlite_master WHERE type COLLATE NOCASE = \'TABLE\' AND name = \'CategoryChange\') THEN -1 ELSE 0 END');
									$categoryChange = $qry->fetchColumn();
									$qry->closeCursor();
									if($categoryChange)
									{
										$qry = $db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM SyncDb.CategoryChange) THEN -1 ELSE 0 END');
										$categoriesAvailable = $qry->fetchColumn();
										$qry->closeCursor();

										if($categoriesAvailable)
										{
											$db->exec('CREATE TABLE Category AS SELECT * FROM SyncDb.Category');
											$db->exec('CREATE TABLE CategoryChange AS SELECT * FROM SyncDb.CategoryChange');
										}
									}

									$qry = $db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM SyncDb.sqlite_master WHERE type COLLATE NOCASE = \'TABLE\' AND name = \'OrderChange\') THEN -1 ELSE 0 END');
									$orderChange = $qry->fetchColumn();
									$qry->closeCursor();
									if($orderChange)
									{
										$qry = $db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM SyncDb.OrderChange) THEN -1 ELSE 0 END');
										$ordersAvailable = $qry->fetchColumn();
										$qry->closeCursor();

										if($ordersAvailable)
										{
											$db->exec('CREATE TABLE [Order] AS SELECT * FROM SyncDb.[Order] WHERE ID IN (SELECT ExternalReference FROM SyncDb.OrderChange)');
											$db->exec('CREATE TABLE OrderChange AS SELECT * FROM SyncDb.OrderChange');
										}
									}

									$db->exec('COMMIT TRANSACTION');
									$db->exec('DETACH DATABASE SyncDB');
									$db->exec('VACUUM');

									$this->sendFile($tmpDb, 'incremental');

									unlink($tmpDb);

									$sendFullDb = false;
								}

								if($sendFullDb)
								{
									$this->sendFile($syncDb);
								}
							}
						}
						catch(Exception $e)
						{
							$this->sendExceptionError($response, $e);
							$response->sendResponse();
						}
					}
					else
					{
						$this->sendSecurityError($response);
						$response->sendResponse();
					}
					die;

				case 'PRODUCTCOUNT':

					if($this->checkHash($helper, $server, $storeId))
					{
						$syncObject = Mage::getModel('codistosync/sync');
						$totals = $syncObject->ProductTotals($storeId);

						$this->sendJsonResponse($response, 200, 'OK', $totals);
						$response->sendResponse();
					}
					else
					{
						$this->sendSecurityError($response);
						$response->sendResponse();
					}
					die;

				case 'EXECUTEFIRST':

					if($this->checkHash($helper, $server, $storeId))
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

							$this->sendPlainResponse($response, 200, 'OK', $result);
							$response->sendResponse();
						}
						catch(Exception $e)
						{
							$this->sendExceptionError($response, $e);
							$response->sendResponse();
						}
					}
					else
					{
						$this->sendSecurityError($response);
						$response->sendResponse();
					}
					die;

				case 'EXECUTECHUNK':

					if($this->checkHash($helper, $server, $storeId))
					{
						try
						{
							$syncObject = Mage::getModel('codistosync/sync');

							$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

							$configurableCount = (int)$request->getQuery('configurablecount');
							if(!$configurableCount || !is_numeric($configurableCount))
								$configurableCount = $this->defaultConfigurableCount;

							$simpleCount = (int)$request->getQuery('simplecount');
							if(!$simpleCount || !is_numeric($simpleCount))
								$simpleCount = $this->defaultSimpleCount;

							if($request->getPost('Init') == '1')
							{
								if(preg_match('/\/codisto\//', $syncDb))
								{
									@array_map('@unlink', glob( Mage::getBaseDir('var').'/codisto-*') );
								}

								$forceInit = $request->getPost('forceinit');
								$forceInit = is_string($forceInit) && $forceInit == '1';

								if(!$forceInit)
								{
									if($helper->canSyncIncrementally($syncDb, $storeId))
									{
										$result = $syncObject->SyncIncremental($simpleCount, $configurableCount);

										$this->sendPlainResponse($response, 200, 'OK', 'incremental-'.$result);
										$response->sendResponse();
										die;
									}
								}

								if(file_exists($syncDb))
									unlink($syncDb);
							}

							if(is_string($request->getQuery('incremental')))
							{
								$result = $syncObject->SyncIncremental($simpleCount, $configurableCount);
								if($result == 'nochange')
									$result = 'complete';
							}
							else
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

								$result = $syncObject->SyncChunk($syncDb, $simpleCount, $configurableCount, $storeId, false);
							}

							if($result == 'complete')
							{
								$result = 'catalog-complete';

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
							}

							$this->sendPlainResponse($response, 200, 'OK', $result);
							$response->sendResponse();
						}
						catch(Exception $e)
						{
							$this->sendExceptionError($response, $e);
							$response->sendResponse();
						}
					}
					else
					{
						$this->sendSecurityError($response);
						$response->sendResponse();
					}
					die;

				case 'CHANGECOMPLETE':

					if($this->checkHash($helper, $server, $storeId))
					{
						try
						{
							$syncObject = Mage::getModel('codistosync/sync');

							$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

							$tmpDb = $helper->getSyncPathTemp('sync');

							file_put_contents($tmpDb, $request->getRawBody());

							$syncObject->SyncChangeComplete($syncDb, $tmpDb, $storeId);

							@unlink($tmpDb);

							$this->sendPlainResponse($response, 200, 'OK', 'ok');
							$response->sendResponse();
						}
						catch(Exception $e)
						{
							$this->sendExceptionError($response, $e);
							$response->sendResponse();
						}
					}
					else
					{
						$this->sendSecurityError($response);
						$response->sendResponse();
					}
					die;

				case 'TAX':

					if($this->checkHash($helper, $server, $storeId))
					{
						try
						{
							$syncObject = Mage::getModel('codistosync/sync');

							$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

							$syncObject->SyncTax($syncDb, $storeId);

							$tmpDb = $helper->getSyncPathTemp('sync');

							$db = new PDO('sqlite:' . $tmpDb);

							$helper->prepareSqliteDatabase($db);

							$db->exec('ATTACH DATABASE \''.$syncDb.'\' AS SyncDB');

							$db->exec('BEGIN EXCLUSIVE TRANSACTION');
							$db->exec('CREATE TABLE TaxClass AS SELECT * FROM SyncDb.TaxClass');
							$db->exec('CREATE TABLE TaxCalculation AS SELECT * FROM SyncDb.TaxCalculation');
							$db->exec('CREATE TABLE TaxCalculationRule AS SELECT * FROM SyncDb.TaxCalculationRule');
							$db->exec('CREATE TABLE TaxCalculationRate AS SELECT * FROM SyncDb.TaxCalculationRate');
							$db->exec('COMMIT TRANSACTION');
							$db->exec('VACUUM');

							$this->sendFile($tmpDb);

							unlink($tmpDb);
						}
						catch(Exception $e)
						{
							$this->sendExceptionError($response, $e);
							$response->sendResponse();
						}
					}
					else
					{
						$this->sendSecurityError($response);
						$response->sendResponse();
					}
					die;

				case 'STOREVIEW':

					if($this->checkHash($helper, $server, $storeId))
					{
						try
						{
							$syncObject = Mage::getModel('codistosync/sync');

							$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

							$syncObject->SyncStores($syncDb, $storeId);

							$tmpDb = $helper->getSyncPathTemp('sync');

							$db = new PDO('sqlite:' . $tmpDb);

							$helper->prepareSqliteDatabase($db);

							$db->exec('ATTACH DATABASE \''.$syncDb.'\' AS SyncDB');

							$db->exec('BEGIN EXCLUSIVE TRANSACTION');
							$db->exec('CREATE TABLE Store AS SELECT * FROM SyncDb.Store');
							$db->exec('COMMIT TRANSACTION');
							$db->exec('VACUUM');

							$this->sendFile($tmpDb);

							unlink($tmpDb);
						}
						catch(Exception $e)
						{
							$this->sendExceptionError($response, $e);
							$response->sendResponse();
						}
					}
					else
					{
						$this->sendSecurityError($response);
						$response->sendResponse();
					}
					die;

				case 'BLOCKS':

					if($this->checkHash($helper, $server, $storeId))
					{
						$syncObject = Mage::getModel('codistosync/sync');

						$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

						try
						{
							$syncObject->SyncStaticBlocks($syncDb, $storeId);
						}
						catch(Exception $e)
						{

						}

						$this->sendPlainResponse($response, 200, 'OK', $result);
						$response->sendResponse();
					}
					else
					{
						$this->sendSecurityError($response);
						$response->sendResponse();
					}
					die;

				case 'ORDERS':

					if($this->checkHash($helper, $server, $storeId))
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

							$helper->prepareSqliteDatabase($db);

							$db->exec('ATTACH DATABASE \''.$syncDb.'\' AS SyncDB');

							$db->exec('BEGIN EXCLUSIVE TRANSACTION');
							$db->exec('CREATE TABLE [Order] AS SELECT * FROM SyncDb.[Order]');
							$db->exec('COMMIT TRANSACTION');
							$db->exec('VACUUM');

							$this->sendFile($tmpDb);

							unlink($tmpDb);
						}
						catch(Exception $e)
						{
							$this->sendExceptionError($response, $e);
							$response->sendResponse();
						}
					}
					else
					{
						$this->sendSecurityError($response);
						$response->sendResponse();
					}
					die;

				case 'TEMPLATE':

					if($this->checkHash($helper, $server, $storeId))
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

										$this->sendJsonResponse($response, 200, 'OK', array( 'ack' => 'ok' ) );
										$response->sendResponse();
									}
									catch(Exception $e)
									{
										$this->sendJsonResponse($response, 500, 'Exception', array( 'ack' => 'failed', 'message' => $e->getMessage() ) );
										$response->sendResponse();
									}
								}
								else
								{
									$syncObject = Mage::getModel('codistosync/sync');

									$syncObject->TemplateRead($templateDb);

									$tmpDb = $helper->getSyncPathTemp('template');

									$db = new PDO('sqlite:' . $tmpDb);

									$helper->prepareSqliteDatabase($db, 1024);

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
										$this->sendPlainResponse($response, 204, 'No Content', '');
										$response->sendResponse();
									}
									else
									{
										$this->sendFile($tmpDb);
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

								$this->sendJsonResponse($response, 200, 'OK', array( 'ack' => 'ok' ) );
								$response->sendResponse();
							}
						}
						catch(Exception $e)
						{
							$this->sendExceptionError($response, $e);
							$response->sendResponse();
						}
					}
					else
					{
						$this->sendSecurityError($response);
						$response->sendResponse();
					}
					die;

				default:

					$this->sendPlainResponse($response, 400, 'Bad Request', 'No Action');
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
			$this->sendConfigError($response);
			$response->sendResponse();
			die;
		}

		if($this->checkHash($helper, $server, $storeId))
		{
			$extSyncFailed = $helper->getSyncPath('external-sync-failed');
			if(file_exists($extSyncFailed))
				unlink($extSyncFailed);

			$extTestFailed = $helper->getSyncPath('external-test-failed');
			if(file_exists($extTestFailed))
				unlink($extTestFailed);

			$version = $helper->getCodistoVersion();

			$this->sendPlainResponse($response, 200, 'OK', 'OK', array( 'X-Codisto-Version' => $version ) );
			$response->sendResponse();
		}
		else
		{
			$this->sendSecurityError($response);
			$response->sendResponse();
		}
		die;
	}

	private function checkHash($helper, $server, $storeId)
	{
		return $helper->checkRequestHash(Mage::getStoreConfig('codisto/hostkey', $storeId), $server);
	}

	private function sendSecurityError($response)
	{
		$this->sendPlainResponse($response, 400, 'Security Error', 'Security Error');
	}

	private function sendConfigError($response)
	{
		$this->sendPlainResponse($response, 500, 'Config Error', 'Config Error');
	}

	private function sendExceptionError($response, $exception)
	{
		$this->sendPlainResponse($response, 500, 'Exception', 'Exception: '.$exception->getMessage().' on line: '.$exception->getLine().' in file: '.$exception->getFile());
	}

	private function sendPlainResponse($response, $status, $statustext, $body, $extraHeaders = null)
	{
		$response->clearAllHeaders();
		//@codingStandardsIgnoreStart
		if(function_exists('http_response_code'))
			http_response_code($status);
		//@codingStandardsIgnoreEnd
		$response->setHttpResponseCode($status);
		$response->setRawHeader('HTTP/1.0 '.$status.' '.$statustext);
		$response->setRawHeader('Status: '.$status.' '.$statustext);
		$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
		$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
		$response->setHeader('Pragma', 'no-cache', true);
		$response->setHeader('Content-Type', 'text/plain; charset=utf-8');

		if(is_array($extraHeaders))
		{
			foreach($extraHeaders as $key => $value)
			{
				$response->setHeader($key, $value);
			}
		}

		$response->setBody($body);
	}

	private function sendJsonResponse($response, $status, $statustext, $body, $extraHeaders = null)
	{
		$response->clearAllHeaders();
		//@codingStandardsIgnoreStart
		if(function_exists('http_response_code'))
			http_response_code($status);
		//@codingStandardsIgnoreEnd
		$response->setHttpResponseCode($status);
		$response->setRawHeader('HTTP/1.0 '.$status.' '.$statustext);
		$response->setRawHeader('Status: '.$status.' '.$statustext);
		$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
		$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
		$response->setHeader('Pragma', 'no-cache', true);
		$response->setHeader('Content-Type', 'application/json');

		if(is_array($extraHeaders))
		{
			foreach($extraHeaders as $key => $value)
			{
				$response->setHeader($key, $value);
			}
		}

		$response->setBody( Zend_Json::encode( $body ) );
	}


	private function sendFile($syncDb, $syncResponse = '')
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
		if($syncResponse)
		{
			header('X-Codisto-SyncResponse: '.$syncResponse);
		}

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
