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
 * @copyright   Copyright (c) 2014 On Technology Pty. Ltd. (http://codisto.com/)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
 
class Codisto_Sync_SyncController extends Mage_Core_Controller_Front_Action
{
	private $defaultSyncTimeout = 10;
	private $defaultSleep = 100000;
	
	public function indexAction()
	{
		$response = $this->getResponse();
	
		$this->getConfig();
		$request = $this->getRequest();
		$request->setDispatched(true);
		$server = $request->getServer();

		if (isset($server['HTTP_X_SYNC'])) {
			if (!isset($server['HTTP_X_ACTION'])) {
				$server['HTTP_X_ACTION'] = '';
			}
			
			switch ($server['HTTP_X_ACTION']) {
				
				case 'GET':

					if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
					{
						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync.db';

						if($request->getQuery('productid') || $request->getQuery('categoryid'))
						{
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
								$productIds = json_decode($request->getQuery('productid'));
								if(!is_array($productIds))
									$productIds = array($productIds);
								
								$db->exec('CREATE TABLE Product AS SELECT * FROM SyncDb.Product WHERE ExternalReference IN ('.implode(',', $productIds).')');
								$db->exec('CREATE TABLE ProductImage AS SELECT * FROM SyncDb.ProductImage WHERE ProductExternalReference IN ('.implode(',', $productIds).')');
								$db->exec('CREATE TABLE CategoryProduct AS SELECT * FROM SyncDb.CategoryProduct WHERE ProductExternalReference IN ('.implode(',', $productIds).')');
								$db->exec('CREATE TABLE SKU AS SELECT * FROM SyncDb.SKU WHERE ProductExternalReference IN ('.implode(',', $productIds).')');
								$db->exec('CREATE TABLE SKUMatrix AS SELECT * FROM SyncDb.SKUMatrix WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference IN ('.implode(',', $productIds).'))');
								$db->exec('CREATE TABLE SKUImage AS SELECT * FROM SyncDb.SKUImage WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference IN ('.implode(',', $productIds).'))');
								
								if($db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM SyncDb.sqlite_master WHERE name = \'ProductDelete\' COLLATE NOCASE AND type = \'table\') THEN 1 ELSE 0 END')->fetchColumn())
									$db->exec('CREATE TABLE ProductDelete AS SELECT * FROM SyncDb.ProductDelete WHERE ExternalReference IN ('.implode(',', $productIds).')');
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
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setRawHeader('Status: 400 Bad Request');
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
						
						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync.db';
						if(file_exists($syncDb))
							unlink($syncDb);
						
						$syncObject->Sync($syncDb);
						
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setBody('done');
						$response->sendResponse();
						
						$indexer->changeStatus(Mage_Index_Model_Process::STATUS_PENDING);
					}
					else
					{
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setRawHeader('Status: 400 Bad Request');
						$response->setBody('Security Error');
						$response->sendResponse();
					}
					die;
					
					
				case 'EXECUTECHUNK':

					if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH'])) {
						
						$indexer = Mage::getModel('index/process');
						$indexer->load('codistoebayindex', 'indexer_code')
									->changeStatus(Mage_Index_Model_Process::STATUS_RUNNING);

						$syncObject = Mage::getModel('codistosync/sync');
						
						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync.db';
						
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
							$result = $syncObject->SyncChunk($syncDb);
							
							if($result == 'complete')
							{
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
						$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setRawHeader('Status: 400 Bad Request');
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
		$server = $this->getRequest()->getServer();
		$response = $this->getResponse();
		
		$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
		$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
		$response->setHeader('Pragma', 'no-cache', true);
		
		$this->getConfig();
		if($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
		{
			$version = (string)Mage::getConfig()->getModuleConfig("Codisto_Sync")->version;
			$response->setHeader('X-Codisto-Version', $version, true);
			
			$response->setBody('OK');
		}

	}
	
	public function checkPluginAction()
	{ // End Point: index.php/codisto-sync/sync/checkPlugin
		$this->getConfig();
		$response = $this->getResponse();
		
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
		$this->getConfig();
		$server = $request->getServer();
		
		if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH'])) {
			
			Mage::getModel('core/config')->saveConfig('codisto/merchantid', null);
			Mage::getModel('core/config')->saveConfig('codisto/hostkey', null);
			
			//Mage::app()->cleanCache();
			Mage::app()->removeCache('config_store_data');
			Mage::app()->getCacheInstance()->cleanType('config');
			Mage::app()->getStore()->resetConfig();

			$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
			$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
			$response->setHeader('Pragma', 'no-cache', true);

			$response->setBody('SUCCESS');
		} else {
			
			$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
			$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
			$response->setHeader('Pragma', 'no-cache', true);
			
			$response->setBody('Invalid Request');
		}
	}

	private function getAllHeaders($extra = false) 
	{
		$server = $this->getRequest()->getServer();
	
		foreach ($server as $name => $value)
		{
			if (substr($name, 0, 5) == 'HTTP_')
			{
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
				$headers[$name] = $value;
			} else if ($name == 'CONTENT_TYPE') {
				$headers['Content-Type'] = $value;
			} else if ($name == 'CONTENT_LENGTH') {
				$headers['Content-Length'] = $value;
			}
		}
		if($extra)
			$headers = array_merge($headers, $extra);
		return $headers;
	}

	private function getConfig()
	{
		$response = $this->getResponse();
		$this->config = array(
			'MerchantID' => Mage::getStoreConfig('codisto/merchantid'),
			'HostKey' => Mage::getStoreConfig('codisto/hostkey')
		);

		if (!$this->config['MerchantID'] || $this->config['MerchantID'] == '')
			$response->setBody('Config Error - Missing MerchantID');
		if (!$this->config['HostKey'] || $this->config['HostKey'] == '')
			$response->setBody('Config Error - Missing HostKey');
	}

	
	private function checkHash($HostKey, $Nonce, $Hash)
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

	private function Send($syncDb)
	{
		header('Cache-Control: no-cache, must-revalidate'); //HTTP 1.1
		header('Pragma: no-cache'); //HTTP 1.0
		header('Expires: Thu, 01 Jan 1970 00:00:00 GMT'); // Date in the past
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename=' . basename($syncDb));
		header('Content-Length: ' . filesize($syncDb));
		
		ob_clean();
		flush();
		
		readfile($syncDb);
	}
	

}
