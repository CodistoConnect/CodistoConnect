<?php
	
	
class Codisto_Sync_Model_Indexer_Ebay extends Mage_Index_Model_Indexer_Abstract
{
	
	const EVENT_MATCH_RESULT_KEY = 'codisto_sync_match_result';
	
	public function getName()
	{
		return 'eBay Index';
	}
	
	public function getDescription()
	{
		return 'Index Catalog data for synchronisation with eBay';
	}
	
	public function matchEvent(Mage_Index_Model_Event $event)
	{
		$data = $event->getNewData();
		$type = $event->getType();

		if (isset($data[self::EVENT_MATCH_RESULT_KEY])) {
			return $data[self::EVENT_MATCH_RESULT_KEY];
		}
		
		$entity = $event->getEntity();
		
		if($entity == Mage_Catalog_Model_Product::ENTITY)
		{
			if($type == Mage_Index_Model_Event::TYPE_SAVE ||
				$type == Mage_Index_Model_Event::TYPE_DELETE)
			{
				$event->addNewData(self::EVENT_MATCH_RESULT_KEY, true);
				return true;
			}
		}
		
		if($entity == Mage_Catalog_Model_Category::ENTITY)
		{
			if($type == Mage_Index_Model_Event::TYPE_SAVE ||
				$type == Mage_Index_Model_Event::TYPE_DELETE)
			{
				$event->addNewData(self::EVENT_MATCH_RESULT_KEY, true);
			}
			return true;
		}
		
		$result = parent::matchEvent($event);
		
		$event->addNewData(self::EVENT_MATCH_RESULT_KEY, $result);

		return $result;
	}
	
	protected function _registerEvent(Mage_Index_Model_Event $event)
	{
		$event->addNewData(self::EVENT_MATCH_RESULT_KEY, true);
		
		$entity = $event->getEntity();

		switch ($entity) {
			case Mage_Catalog_Model_Product::ENTITY:
			
				$event->addNewData('codisto_sync_product', true);
				break;

			case Mage_Catalog_Model_Category::ENTITY:
				$event->addNewData('codisto_sync_category', true);
				break;

			case Mage_Catalog_Model_Convert_Adapter_Product::ENTITY:
			
				$event->addNewData('codisto_sync_bulkproduct', true);
				break;
		}
		return $this;
	}
	
	protected function _processEvent(Mage_Index_Model_Event $event)
	{
		$data = $event->getNewData();
		$type = $event->getType();
		
		if(isset($data['codisto_sync_category']))
		{
			$syncDb = Mage::getBaseDir("var") . "/codisto-ebay-sync.db";

			$syncObject = Mage::getModel('codistosync/sync');
			
			if(Mage_Index_Model_Event::TYPE_SAVE == $type)
				$syncObject->UpdateCategory($syncDb, $event->getDataObject()->getId());
			else
				$syncObject->DeleteCategory($syncDb, $event->getDataObject()->getId());

			try
			{
				$MerchantID = Mage::getStoreConfig('codisto/merchantid');
				$HostKey = Mage::getStoreConfig('codisto/hostkey');
				
				$client = new Zend_Http_Client('https://api.codisto.com/'.$MerchantID, array( 'keepalive' => true, 'maxredirects' => 0 ));
				$client->setStream();
				$client->setHeaders('X-HostKey', $HostKey);
				
				$client->setRawData('action=sync&categoryid='.$event->getDataObject()->getId())->request('POST');
			}
			catch(Exception $e)
			{
				
			}
		}
		
		if(isset($data['codisto_sync_product']))
		{
			$syncDb = Mage::getBaseDir("var") . "/codisto-ebay-sync.db";

			$syncObject = Mage::getModel('codistosync/sync');
			$syncIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild($event->getDataObject()->getId());
			
			if(empty($syncIds))
				$syncIds = array($event->getDataObject()->getId());
				
			if($type == Mage_Index_Model_Event::TYPE_DELETE 
					&& count($syncIds) == 1 
					&& $syncIds[0] == $event->getDataObject()->getId())
			{
				$syncObject->DeleteProduct($syncDb, $event->getDataObject()->getId());
			}
			else
			{
				$syncObject->UpdateProducts($syncDb, $syncIds);
			}
			
			try
			{
				$MerchantID = Mage::getStoreConfig('codisto/merchantid');
				$HostKey = Mage::getStoreConfig('codisto/hostkey');
				
				$client = new Zend_Http_Client('https://api.codisto.com/'.$MerchantID, array( 'keepalive' => true, 'maxredirects' => 0 ));
				$client->setStream();
				$client->setHeaders('X-HostKey', $HostKey);

				$productIds = '';
				if(count($syncIds) == 1)
					$productIds = $syncIds[0];
				else
					$productIds = '['.implode(',', $syncIds).']';

				$client->setRawData('action=sync&productid='.$productIds)->request('POST');
			}
			catch(Exception $e)
			{
				
			}
		}
	}
	
	public function reindexAll()
	{
		$MerchantID = Mage::getStoreConfig('codisto/merchantid');
		$HostKey = Mage::getStoreConfig('codisto/hostkey');
		
		$client = new Zend_Http_Client('https://api.codisto.com/'.$MerchantID, array( 'keepalive' => true, 'maxredirects' => 0 ));
		$client->setHeaders('X-HostKey', $HostKey);

		$remoteResponse = $client->setRawData('action=sync')->request('POST');
	}
}
