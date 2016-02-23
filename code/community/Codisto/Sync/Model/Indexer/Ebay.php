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

		if($entity == Mage_CatalogInventory_Model_Stock_Item::ENTITY)
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

		if($entity == Mage_Core_Model_Store::ENTITY ||
			$entity == Mage_Core_Model_Store_Group::ENTITY)
		{
			$event->addNewData(self::EVENT_MATCH_RESULT_KEY, true);
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

			case Mage_CatalogInventory_Model_Stock_Item::ENTITY:

				$event->addNewData('codisto_sync_stock', true);
				break;

			case Mage_Catalog_Model_Category::ENTITY:

				$event->addNewData('codisto_sync_category', true);
				break;

			case Mage_Catalog_Model_Convert_Adapter_Product::ENTITY:

				$event->addNewData('codisto_sync_bulkproduct', true);
				break;

			case Mage_Core_Model_Store::ENTITY:
			case Mage_Core_Model_Store_Group::ENTITY:

				$event->addNewData('codisto_sync_store', true);
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
			if($event->getDataObject())
			{
				$syncedCategories = Mage::registry('codisto_synced_categories');
				if(!is_array($syncedCategories))
				{
					$syncedCategories = array();
				}

				// always synchronise the admin store on any edit
				$syncStores = array(0);

				$eventData = $event->getDataObject()->getData();
				$storeId = isset($eventData['store_id']) ? $eventData['store_id'] : 0;
				if(!isset($storeId) || !$storeId)
					$storeId = 0;

				if($storeId != 0)
				{
					$defaultMerchantId = Mage::getConfig()->getNode('stores/admin/codisto/merchantid');
					$storeMerchantId = Mage::getStoreConfig('codisto/merchantid', $storeId);

					// if the default Codisto merchantid is different at this store level
					// explicitly synchronise it as well
					if($defaultMerchantId != $storeMerchantId)
					{
						$syncStores[] = $storeId;
					}
				}
				else
				{
					$defaultMerchantId = Mage::getConfig()->getNode('stores/admin/codisto/merchantid');

					$stores = Mage::getModel('core/store')->getCollection();

					foreach($stores as $store)
					{
						if($store->getId() != 0)
						{
							$storeMerchantId = Mage::getStoreConfig('codisto/merchantid', $store->getId());

							if($defaultMerchantId != $storeMerchantId)
							{
								$syncStores[] = $store->getId();
							}
						}
					}
				}

				$syncObject = Mage::getModel('codistosync/sync');

				$categoryId = $event->getDataObject()->getId();

				if(!in_array($categoryId, $syncedCategories))
				{
					$syncedCategories[] = $categoryId;

					Mage::unregister('codisto_synced_categories');
					Mage::register('codisto_synced_categories', $syncedCategories);

					$merchants = array();
					$merchantSignalled = array();

					foreach($syncStores as $storeId)
					{
						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

						if(Mage_Index_Model_Event::TYPE_SAVE == $type)
							$syncObject->UpdateCategory($syncDb, $categoryId, $storeId);
						else
							$syncObject->DeleteCategory($syncDb, $categoryId, $storeId);

						$merchantid = Mage::getStoreConfig('codisto/merchantid', $storeId);
						$hostkey = Mage::getStoreConfig('codisto/hostkey', $storeId);

						$merchantlist = Zend_Json::decode($merchantid);
						if(!is_array($merchantlist))
							$merchantlist = array($merchantlist);

						foreach($merchantlist as $merchantid)
						{
							if(!in_array($merchantid, $merchantSignalled, true))
							{
								$merchantSignalled[] = $merchantid;
								$merchants[] = array('merchantid' => $merchantid, 'hostkey' => $hostkey, 'storeid' => $storeId );
							}
						}
					}

					Mage::helper('codistosync')->signal($merchants, 'action=sync&categoryid='.$categoryId);
				}
			}
		}

		if(isset($data['codisto_sync_stock']))
		{
			if($event->getDataObject())
			{
				$syncedProducts = Mage::registry('codisto_synced_products');
				if(!is_array($syncedProducts))
				{
					$syncedProducts = array();
				}

				// always synchronise the admin store on any edit
				$syncStores = array(0);

				$eventData = $event->getDataObject()->getData();
				$storeId = isset($eventData['store_id']) ? $eventData['store_id'] : 0;
				if(!isset($storeId) || !$storeId)
					$storeId = 0;

				if($storeId != 0)
				{
					$defaultMerchantId = Mage::getConfig()->getNode('stores/admin/codisto/merchantid');
					$storeMerchantId = Mage::getStoreConfig('codisto/merchantid', $storeId);

					// if the default Codisto merchantid is different at this store level
					// explicitly synchronise it as well
					if($defaultMerchantId != $storeMerchantId)
					{
						$syncStores[] = $storeId;
					}
				}
				else
				{
					$defaultMerchantId = Mage::getConfig()->getNode('stores/admin/codisto/merchantid');

					$stores = Mage::getModel('core/store')->getCollection();

					foreach($stores as $store)
					{
						if($store->getId() != 0)
						{
							$storeMerchantId = Mage::getStoreConfig('codisto/merchantid', $store->getId());

							if($defaultMerchantId != $storeMerchantId)
							{
								$syncStores[] = $store->getId();
							}
						}
					}
				}

				$syncObject = Mage::getModel('codistosync/sync');
				$syncIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild($event->getDataObject()->getProductId());

				if(empty($syncIds))
					$syncIds = array($event->getDataObject()->getProductId());

				$syncIds = array_diff($syncIds, $syncedProducts);

				if(!empty($syncIds))
				{
					foreach($syncIds as $productid)
					{
						if(!in_array($productid, $syncedProducts))
						{
							$syncedProducts[] = $productid;
						}
					}

					Mage::unregister('codisto_synced_products');
					Mage::register('codisto_synced_products', $syncedProducts);

					$productIds = '';
					if(count($syncIds) == 1)
						$productIds = $syncIds[0];
					else
						$productIds = '['.implode(',', $syncIds).']';

					$merchants = array();
					$merchantSignalled = array();

					foreach($syncStores as $storeId)
					{
						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

						if($type == Mage_Index_Model_Event::TYPE_DELETE
								&& count($syncIds) == 1
								&& $syncIds[0] == $event->getDataObject()->getId())
						{
							$syncObject->DeleteProduct($syncDb, $event->getDataObject()->getId(), $storeId);
						}
						else
						{
							$syncObject->UpdateProducts($syncDb, $syncIds, $storeId);
						}

						$merchantid = Mage::getStoreConfig('codisto/merchantid', $storeId);
						$hostkey = Mage::getStoreConfig('codisto/hostkey', $storeId);

						$merchantlist = Zend_Json::decode($merchantid);
						if(!is_array($merchantlist))
							$merchantlist = array($merchantlist);

						foreach($merchantlist as $merchantid)
						{
							if(!in_array($merchantid, $merchantSignalled, true))
							{
								$merchantSignalled[] = $merchantid;
								$merchants[] = array('merchantid' => $merchantid, 'hostkey' => $hostkey, 'storeid' => $storeId );
							}
						}
					}

					Mage::helper('codistosync')->signal($merchants, 'action=sync&productid='.$productIds);
				}
			}
		}

		if(isset($data['codisto_sync_product']))
		{
			if($event->getDataObject())
			{
				$syncedProducts = Mage::registry('codisto_synced_products');
				if(!is_array($syncedProducts))
				{
					$syncedProducts = array();
				}

				// always synchronise the admin store on any edit
				$syncStores = array(0);
				$eventData = $event->getDataObject()->getData();

				$storeId = isset($eventData['store_id']) ? $eventData['store_id'] : 0;
				if(!isset($storeId) || !$storeId)
					$storeId = 0;

				if($storeId != 0)
				{
					$defaultMerchantId = Mage::getConfig()->getNode('stores/admin/codisto/merchantid');
					$storeMerchantId = Mage::getStoreConfig('codisto/merchantid', $storeId);

					// if the default Codisto merchantid is different at this store level
					// explicitly synchronise it as well
					if($defaultMerchantId != $storeMerchantId)
					{
						$syncStores[] = $storeId;
					}
				}
				else
				{
					$defaultMerchantId = Mage::getConfig()->getNode('stores/admin/codisto/merchantid');

					$stores = Mage::getModel('core/store')->getCollection();

					foreach($stores as $store)
					{
						if($store->getId() != 0)
						{
							$storeMerchantId = Mage::getStoreConfig('codisto/merchantid', $store->getId());

							if($defaultMerchantId != $storeMerchantId)
							{
								$syncStores[] = $store->getId();
							}
						}
					}
				}

				$syncObject = Mage::getModel('codistosync/sync');
				$syncIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild($event->getDataObject()->getId());

				if(empty($syncIds))
					$syncIds = array($event->getDataObject()->getId());

				$syncIds = array_diff($syncIds, $syncedProducts);

				if(!empty($syncIds))
				{
					foreach($syncIds as $productid)
					{
						if(!in_array($productid, $syncedProducts))
						{
							$syncedProducts[] = $productid;
						}
					}

					Mage::unregister('codisto_synced_products');
					Mage::register('codisto_synced_products', $syncedProducts);

					$productIds = '';
					if(count($syncIds) == 1)
						$productIds = $syncIds[0];
					else
						$productIds = '['.implode(',', $syncIds).']';

					$merchants = [];
					$merchantSignalled = array();

					foreach($syncStores as $storeId)
					{
						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

						if($type == Mage_Index_Model_Event::TYPE_DELETE
								&& count($syncIds) == 1
								&& $syncIds[0] == $event->getDataObject()->getId())
						{
							$syncObject->DeleteProduct($syncDb, $event->getDataObject()->getId(), $storeId);
						}
						else
						{
							$syncObject->UpdateProducts($syncDb, $syncIds, $storeId);
						}

						$merchantid = Mage::getStoreConfig('codisto/merchantid', $storeId);
						$hostkey = Mage::getStoreConfig('codisto/hostkey', $storeId);

						$merchantlist = Zend_Json::decode($merchantid);
						if(!is_array($merchantlist))
							$merchantlist = array($merchantlist);

						foreach($merchantlist as $merchantid)
						{
							if(!in_array($merchantid, $merchantSignalled, true))
							{
								$merchantSignalled[] = $merchantid;
								$merchants[] = array('merchantid' => $merchantid, 'hostkey' => $hostkey, 'storeid' => $storeId );
							}
						}
					}

					Mage::helper('codistosync')->signal($merchants, 'action=sync&productid='.$productIds);
				}
			}
		}

		if(isset($data['codisto_sync_store']))
		{
			if(!Mage::registry('codisto_synced_stores'))
			{
				$merchants = array();
				$visited = array();

				$stores = Mage::getModel('core/store')->getCollection();

				foreach($stores as $store)
				{
					$merchantlist = Zend_Json::decode($store->getConfig('codisto/merchantid'));
					if($merchantlist)
					{
						if(!is_array($merchantlist))
							$merchantlist = array($merchantlist);

						foreach($merchantlist as $merchantId)
						{
							if(!in_array($merchantId, $visited, true))
							{
								$merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $store->getConfig('codisto/hostkey'), 'storeid' => $store->getId() );
								$visited[] = $merchantId;
							}
						}
					}
				}

				unset($visited);

				Mage::register('codisto_synced_stores', true);

				Mage::helper('codistosync')->signal($merchants, 'action=syncstores');
			}
		}

		if(isset($data['codisto_sync_category']))
		{
			$this->reindexAll();
		}

	}

	public function reindexAll()
	{
		$merchants = array();
		$visited = array();

		$stores = Mage::getModel('core/store')->getCollection();

		foreach($stores as $store)
		{
			$merchantlist = Zend_Json::decode($store->getConfig('codisto/merchantid'));
			if($merchantlist)
			{
				if(!is_array($merchantlist))
					$merchantlist = array($merchantlist);

				foreach($merchantlist as $merchantId)
				{
					if(!in_array($merchantId, $visited, true))
					{
						$merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $store->getConfig('codisto/hostkey'), 'storeid' => $store->getId() );
						$visited[] = $merchantId;
					}
				}
			}
		}

		unset($visited);

		Mage::helper('codistosync')->signal($merchants, 'action=sync');
	}
}
