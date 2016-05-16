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

class Codisto_Sync_Model_Sync
{
	private $currentEntityId;
	private $productsProcessed;
	private $ordersProcessed;

	private $useTaxHelper = true;

	private $taxCalculation;
	private $rateRequest;

	private $ebayGroupId;

	private $attributeCache;
	private $groupCache;
	private $optionCache;
	private $optionTextCache;

	public function __construct()
	{
		if(method_exists('Mage', 'getEdition'))
		{
			$edition = Mage::getEdition();
		}
		else
		{
			$edition = 'Community';
		}

		$version = Mage::getVersionInfo();

		$versionNumber = (int)(
			((int)$version['major']*10000)+
			((int)$version['minor']*100)+
			((int)$version['revision']*10));

		$this->useTaxHelper = ($edition == 'Enterprise' && $versionNumber > 11300) || ($edition == 'Community' && $versionNumber >= 10810);

		if(!$this->useTaxHelper)
		{
			$this->taxCalculation = Mage::getModel('tax/calculation');
		}

		$this->attributeCache = array();
		$this->groupCache = array();
		$this->optionCache = array();
		$this->optionTextCache = array();

		$ebayGroup = Mage::getModel('customer/group');
		$ebayGroup->load('eBay', 'customer_group_code');

		$this->ebayGroupId = $ebayGroup->getId();
		if(!$this->ebayGroupId)
			$this->ebayGroupId = Mage_Customer_Model_Group::NOT_LOGGED_IN_ID;
	}

	private function FilesInDir($dir, $prefix = '')
	{
		$dir = rtrim($dir, '\\/');
		$result = array();

		try
		{
			if(is_dir($dir))
			{
				$scan = @scandir($dir);

				if($scan !== false)
				{
					foreach ($scan as $f) {
						if ($f !== '.' and $f !== '..') {
							if (is_dir("$dir/$f")) {
								$result = array_merge($result, $this->FilesInDir("$dir/$f", "$f/"));
							} else {
								$result[] = $prefix.$f;
							}
						}
					}
				}
			}
		}
		catch(Exception $e)
		{

		}

		return $result;
	}

	public function TemplateRead($templateDb)
	{
		$ebayDesignDir = Mage::getBaseDir('design').'/ebay/';

		try
		{
			$db = $this->GetTemplateDb($templateDb);

			$insert = $db->prepare('INSERT OR IGNORE INTO File(Name, Content, LastModified) VALUES (?, ?, ?)');
			$update = $db->prepare('UPDATE File SET Content = ?, Changed = -1 WHERE Name = ? AND LastModified != ?');

			$filelist = $this->FilesInDir(Mage::getBaseDir('design').'/ebay/');

			$db->exec('BEGIN EXCLUSIVE TRANSACTION');

			foreach ($filelist as $key => $name)
			{
				try
				{
					$fileName = $ebayDesignDir.$name;

					if(!in_array($name, array('README')))
					{
						$content = @file_get_contents($fileName);
						if($content !== false)
						{
							$stat = stat($fileName);

							$lastModified = strftime('%Y-%m-%d %H:%M:%S', $stat['mtime']);

							$update->bindParam(1, $content);
							$update->bindParam(2, $name);
							$update->bindParam(3, $lastModified);
							$update->execute();

							if($update->rowCount() == 0)
							{
								$insert->bindParam(1, $name);
								$insert->bindParam(2, $content);
								$insert->bindParam(3, $lastModified);
								$insert->execute();
							}
						}
					}
				}
				catch(Exception $e)
				{

				}
			}
			$db->exec('COMMIT TRANSACTION');
		}
		catch(Exception $e)
		{
			return $e->getMessage();
		}

		return 'ok';
	}

	public function TemplateWrite($templateDb)
	{
		$ebayDesignDir = Mage::getBaseDir('design').'/ebay/';

		try
		{
			$db = new PDO('sqlite:' . $templateDb);

			Mage::helper('codistosync')->prepareSqliteDatabase($db);

			$files = $db->prepare('SELECT Name, Content FROM File');
			$files->execute();

			$files->bindColumn(1, $name);
			$files->bindColumn(2, $content);

			while($files->fetch())
			{
				$fileName = $ebayDesignDir.$name;

				if(strpos($name, '..') === false)
				{
					if(!file_exists($fileName))
					{
						$dir = dirname($fileName);

						if(!is_dir($dir))
						{
							mkdir($dir.'/', 0755, true);
						}

						@file_put_contents($fileName, $content);
					}
				}
			}

			$files->closeCursor();
		}
		catch(Exception $e)
		{
			return $e->getMessage();
		}

		return 'ok';
	}

	public function UpdateCategory($syncDb, $id, $storeId)
	{
		$store = Mage::app()->getStore($storeId);

		$db = $this->GetSyncDb($syncDb);

		$insertCategory = $db->prepare('INSERT OR REPLACE INTO Category(ExternalReference, Name, ParentExternalReference, LastModified, Enabled, Sequence) VALUES(?,?,?,?,?,?)');

		$category = Mage::getModel('catalog/category', array('disable_flat' => true))->getCollection()
							->addAttributeToSelect(array('name', 'image', 'is_active'), 'left')
							->addAttributeToFilter('entity_id', array('eq' => $id));

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		Mage::getSingleton('core/resource_iterator')->walk($category->getSelect(), array(array($this, 'SyncCategoryData')), array( 'db' => $db, 'preparedStatement' => $insertCategory, 'store' => $store ));

		$db->exec('COMMIT TRANSACTION');
	}

	public function DeleteCategory($syncDb, $id, $storeId)
	{
		$db = $this->GetSyncDb($syncDb);

		$args = array();
		$args[] = $id;

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		$db->exec('CREATE TABLE IF NOT EXISTS CategoryDelete(ExternalReference text NOT NULL PRIMARY KEY);'.
					'INSERT OR IGNORE INTO CategoryDelete VALUES('.$id.');'.
					'DELETE FROM Category WHERE ExternalReference = '.$id.';'.
					'DELETE FROM CategoryProduct WHERE CategoryExternalReference = '.$id);

		$db->exec('COMMIT TRANSACTION');
	}

	public function UpdateProducts($syncDb, $ids, $storeId)
	{
		$store = Mage::app()->getStore($storeId);

		$db = $this->GetSyncDb($syncDb);

		$insertCategoryProduct = $db->prepare('INSERT OR IGNORE INTO CategoryProduct(ProductExternalReference, CategoryExternalReference, Sequence) VALUES(?,?,?)');
		$insertProduct = $db->prepare('INSERT INTO Product(ExternalReference, Type, Code, Name, Price, ListPrice, TaxClass, Description, Enabled, StockControl, StockLevel, Weight, InStore) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
		$checkProduct = $db->prepare('SELECT CASE WHEN EXISTS(SELECT 1 FROM Product WHERE ExternalReference = ?) THEN 1 ELSE 0 END');
		$insertSKU = $db->prepare('INSERT OR IGNORE INTO SKU(ExternalReference, Code, ProductExternalReference, Name, StockControl, StockLevel, Price, Enabled, InStore) VALUES(?,?,?,?,?,?,?,?,?)');
		$insertSKULink = $db->prepare('INSERT OR REPLACE INTO SKULink (SKUExternalReference, ProductExternalReference, Price) VALUES (?, ?, ?)');
		$insertSKUMatrix = $db->prepare('INSERT INTO SKUMatrix(SKUExternalReference, ProductExternalReference, Code, OptionName, OptionValue, ProductOptionExternalReference, ProductOptionValueExternalReference) VALUES(?,?,?,?,?,?,?)');
		$insertImage = $db->prepare('INSERT INTO ProductImage(ProductExternalReference, URL, Tag, Sequence, Enabled) VALUES(?,?,?,?,?)');
		$insertProductOptionValue = $db->prepare('INSERT INTO ProductOptionValue (ExternalReference, Sequence) VALUES (?,?)');
		$insertProductHTML = $db->prepare('INSERT OR IGNORE INTO ProductHTML(ProductExternalReference, Tag, HTML) VALUES (?, ?, ?)');
		$clearAttribute = $db->prepare('DELETE FROM ProductAttributeValue WHERE ProductExternalReference = ?');
		$insertAttribute = $db->prepare('INSERT OR REPLACE INTO Attribute(ID, Code, Label, Type, Input) VALUES (?, ?, ?, ?, ?)');
		$insertAttributeGroup = $db->prepare('INSERT OR IGNORE INTO AttributeGroup(ID, Name) VALUES(?, ?)');
		$insertAttributeGroupMap = $db->prepare('INSERT OR IGNORE INTO AttributeGroupMap(GroupID, AttributeID) VALUES(?,?)');
		$insertProductAttribute = $db->prepare('INSERT OR IGNORE INTO ProductAttributeValue(ProductExternalReference, AttributeID, Value) VALUES (?, ?, ?)');
		$clearProductQuestion = $db->prepare('DELETE FROM ProductQuestionAnswer WHERE ProductQuestionExternalReference IN (SELECT ExternalReference FROM ProductQuestion WHERE ProductExternalReference = ?1); DELETE FROM ProductQuestion WHERE ProductExternalReference = ?1');
		$insertProductQuestion = $db->prepare('INSERT OR REPLACE INTO ProductQuestion(ExternalReference, ProductExternalReference, Name, Type, Sequence) VALUES (?, ?, ?, ?, ?)');
		$insertProductAnswer = $db->prepare('INSERT INTO ProductQuestionAnswer(ProductQuestionExternalReference, Value, PriceModifier, SKUModifier, Sequence) VALUES (?, ?, ?, ?, ?)');

		$this->productsProcessed = array();

		$coreResource = Mage::getSingleton('core/resource');

		$catalogWebsiteName = $coreResource->getTableName('catalog/product_website');
		$storeName = $coreResource->getTableName('core/store');
		$superLinkName = $coreResource->getTableName('catalog/product_super_link');

		// Configurable products
		$configurableProducts = $this->getProductCollection()
							->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'special_from_date', 'special_to_date', 'status', 'tax_class_id', 'weight'), 'left')
							->addAttributeToFilter('type_id', array('eq' => 'configurable'));

		$sqlCheckModified = '(`e`.entity_id IN ('.implode(',', $ids).') OR `e`.entity_id IN (SELECT parent_id FROM `'.$superLinkName.'` WHERE product_id IN ('.implode(',', $ids).')))';

		$configurableProducts->getSelect()
								->columns(array('codisto_in_store'=> new Zend_Db_Expr('CASE WHEN `e`.entity_id IN (SELECT product_id FROM `'.$catalogWebsiteName.'` WHERE website_id IN (SELECT website_id FROM `'.$storeName.'` WHERE store_id = '.$storeId.' OR EXISTS(SELECT 1 FROM `'.$storeName.'` WHERE store_id = '.$storeId.' AND website_id = 0))) THEN -1 ELSE 0 END')))
								->where($sqlCheckModified);

		// Simple Products not participating as configurable skus
		$simpleProducts = $this->getProductCollection()
							->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'special_from_date', 'special_to_date', 'status', 'tax_class_id', 'weight'), 'left')
							->addAttributeToFilter('type_id', array('eq' => 'simple'))
							->addAttributeToFilter('entity_id', array('in' => $ids));

		$simpleProducts->getSelect()
								->columns(array('codisto_in_store'=> new Zend_Db_Expr('CASE WHEN `e`.entity_id IN (SELECT product_id FROM `'.$catalogWebsiteName.'` WHERE website_id IN (SELECT website_id FROM `'.$storeName.'` WHERE store_id = '.$storeId.' OR EXISTS(SELECT 1 FROM `'.$storeName.'` WHERE store_id = '.$storeId.' AND website_id = 0))) THEN -1 ELSE 0 END')));

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		$db->exec('DELETE FROM Product WHERE ExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM ProductImage WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM ProductHTML WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM SKUMatrix WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM SKULink WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM SKU WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM ProductQuestionAnswer WHERE ProductQuestionExternalReference IN (SELECT ExternalReference FROM ProductQuestion WHERE ProductExternalReference IN ('.implode(',', $ids).'))');
		$db->exec('DELETE FROM ProductQuestion WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM CategoryProduct WHERE ProductExternalReference IN ('.implode(',', $ids).')');

		Mage::getSingleton('core/resource_iterator')->walk($configurableProducts->getSelect(), array(array($this, 'SyncConfigurableProductData')),
			array(
				'type' => 'configurable',
				'db' => $db,
				'preparedStatement' => $insertProduct,
				'preparedcheckproductStatement' => $checkProduct,
				'preparedskuStatement' => $insertSKU,
				'preparedskulinkStatement' => $insertSKULink,
				'preparedskumatrixStatement' => $insertSKUMatrix,
				'preparedcategoryproductStatement' => $insertCategoryProduct,
				'preparedimageStatement' => $insertImage,
				'preparedproductoptionvalueStatement' => $insertProductOptionValue,
				'preparedproducthtmlStatement' => $insertProductHTML,
				'preparedclearattributeStatement' => $clearAttribute,
				'preparedattributeStatement' => $insertAttribute,
				'preparedattributegroupStatement' => $insertAttributeGroup,
				'preparedattributegroupmapStatement' => $insertAttributeGroupMap,
				'preparedproductattributeStatement' => $insertProductAttribute,
				'preparedclearproductquestionStatement' => $clearProductQuestion,
				'preparedproductquestionStatement' => $insertProductQuestion,
				'preparedproductanswerStatement' => $insertProductAnswer,
				'store' => $store )
		);

		Mage::getSingleton('core/resource_iterator')->walk($simpleProducts->getSelect(), array(array($this, 'SyncSimpleProductData')),
			array(
				'type' => 'simple',
				'db' => $db,
				'preparedStatement' => $insertProduct,
				'preparedcheckproductStatement' => $checkProduct,
				'preparedcategoryproductStatement' => $insertCategoryProduct,
				'preparedimageStatement' => $insertImage,
				'preparedproducthtmlStatement' => $insertProductHTML,
				'preparedclearattributeStatement' => $clearAttribute,
				'preparedattributeStatement' => $insertAttribute,
				'preparedattributegroupStatement' => $insertAttributeGroup,
				'preparedattributegroupmapStatement' => $insertAttributeGroupMap,
				'preparedproductattributeStatement' => $insertProductAttribute,
				'preparedclearproductquestionStatement' => $clearProductQuestion,
				'preparedproductquestionStatement' => $insertProductQuestion,
				'preparedproductanswerStatement' => $insertProductAnswer,
				'store' => $store )
		);

		$insertProductOptionValue = $db->prepare('INSERT INTO ProductOptionValue (ExternalReference, Sequence) VALUES (?,?)');

		$options = Mage::getResourceModel('eav/entity_attribute_option_collection')
					->setPositionOrder('asc', true)
					->load();

		foreach($options as $opt){
			$sequence = $opt->getSortOrder();
			$optId = $opt->getId();
			$insertProductOptionValue->execute(array($optId, $sequence));
		}

		$db->exec('COMMIT TRANSACTION');
	}

	public function DeleteProduct($syncDb, $ids, $storeId)
	{
		$db = $this->GetSyncDb($syncDb);

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		if(!is_array($ids))
		{
			$ids = array($ids);
		}

		foreach($ids as $id)
		{
			$db->exec(	'CREATE TABLE IF NOT EXISTS ProductDelete(ExternalReference text NOT NULL PRIMARY KEY);'.
						'INSERT OR IGNORE INTO ProductDelete VALUES('.$id.');'.
						'DELETE FROM Product WHERE ExternalReference = '.$id.';'.
						'DELETE FROM ProductImage WHERE ProductExternalReference = '.$id.';'.
						'DELETE FROM ProductHTML WHERE ProductExternalReference = '.$id.';'.
						'DELETE FROM SKULink WHERE ProductExternalReference = '.$id.';'.
						'DELETE FROM SKUMatrix WHERE ProductExternalReference = '.$id.';'.
						'DELETE FROM SKU WHERE ProductExternalReference = '.$id.';'.
						'DELETE FROM CategoryProduct WHERE ProductExternalReference = '.$id);
		}

		$db->exec('COMMIT TRANSACTION');
	}

	public function SyncCategoryData($args)
	{
		$categoryData = $args['row'];

		if($categoryData['level'] < 2)
			return;

		$insertSQL = $args['preparedStatement'];
		$insertFields = array('entity_id', 'name', 'parent_id', 'updated_at', 'is_active', 'position');

		if($categoryData['level'] == 2)
			$categoryData['parent_id'] = 0;

		$data = array();
		foreach ($insertFields as $key)
		{

			$value = $categoryData[$key];

			if(!$value) {
				if($key == 'entity_id') {
					return;
				} else if ($key == 'name') {
					$value = '';
				} else if ($key == 'parent_id') {
					$value = 0;
				} else if ($key == 'updated_at') {
					$value = '1970-01-01 00:00:00';
				} else if ($key == 'is_active') {
					$value = 0;
				} else if ($key == 'position') {
					$value = 0;
				}
			}

			$data[] = $value;
		}

		$insertSQL->execute($data);
	}

	public function SyncProductPrice($store, $parentProduct, $options = null)
	{
		$addInfo = new Varien_Object();

		if(is_array($options))
		{
			$addInfo->setData(array(
				'product' => $parentProduct->getId(),
				'qty' => 1,
				'super_attribute' => $options
			));
		}
		else
		{
			$addInfo->setQty(1);
		}

		$parentProduct->unsetData('final_price');

		$parentProduct->getTypeInstance(true)->processConfiguration($addInfo, $parentProduct, Mage_Catalog_Model_Product_Type_Abstract::PROCESS_MODE_LITE);

		$price = $this->getExTaxPrice($parentProduct, $parentProduct->getFinalPrice(), $store);

		return $price;
	}

	public function SyncSKUData($args)
	{
		$skuData = $args['row'];
		$db = $args['db'];

		$store = $args['store'];

		$insertSKULinkSQL = $args['preparedskulinkStatement'];
		$insertSKUMatrixSQL = $args['preparedskumatrixStatement'];

		$attributes = $args['attributes'];

		$product = Mage::getModel('catalog/product');
		$product->setData($skuData)
				->setStore($store)
				->setStoreId($store->getId())
				->setCustomerGroupId($this->ebayGroupId);

		$stockItem = Mage::getModel('cataloginventory/stock_item');
		$stockItem->setStockId(Mage_CatalogInventory_Model_Stock::DEFAULT_STOCK_ID)
					->assignProduct($product);

		$productParent = $args['parent_product'];

		$attributeCodes = array();

		foreach($attributes as $attribute)
		{
			$attributeCodes[] = $attribute->getProductAttribute()->getAttributeCode();
		}

		$attributeValues = Mage::getResourceSingleton('catalog/product')->getAttributeRawValue($skuData['entity_id'], $attributeCodes, $store->getId());

		if(!is_array($attributeValues))
			$attributeValues = array( $attributeCodes[0] => $attributeValues );

		$options = array();

		foreach($attributes as $attribute)
		{
			$productAttribute = $attribute->getProductAttribute();

			$options[$productAttribute->getId()] = $attributeValues[$productAttribute->getAttributeCode()];
		}
		$price = $this->SyncProductPrice($store, $productParent, $options);

		if(!$price)
			$price = 0;

		$insertSKULinkSQL->execute(array($skuData['entity_id'], $args['parent_id'], $price));


		// SKU Matrix
		foreach($attributes as $attribute)
		{
			$productAttribute = $attribute->getProductAttribute();
			$productOptionId = $productAttribute->getId();
			$productOptionValueId = $attributeValues[$productAttribute->getAttributeCode()];

			if(isset($productOptionValueId))
			{
				$attributeName = $attribute->getLabel();
				$attributeValue = $productAttribute->getSource()->getOptionText($productOptionValueId);

				$insertSKUMatrixSQL->execute(array(
					$skuData['entity_id'],
					$args['parent_id'],
					'',
					$attributeName,
					$attributeValue,
					$productOptionId,
					$productOptionValueId));
			}
		}
	}

	public function SyncConfigurableProductData($args)
	{
		$productData = $args['row'];

		$store = $args['store'];
		$db = $args['db'];

		$insertSQL = $args['preparedskuStatement'];
		$insertSKULinkSQL = $args['preparedskulinkStatement'];
		$insertCategorySQL = $args['preparedcategoryproductStatement'];
		$insertSKUMatrixSQL = $args['preparedskumatrixStatement'];

		$this->SyncSimpleProductData(array_merge($args, array('row' => $productData)));

		$product = Mage::getModel('catalog/product')
					->setData($productData)
					->setStore($store)
					->setStoreId($store->getId())
					->setCustomerGroupId($this->ebayGroupId)
					->setIsSuperMode(true);

		$configurableData = Mage::getModel('catalog/product_type_configurable');

		$configurableAttributes = $configurableData->getConfigurableAttributes($product);

		$childProducts = $configurableData->getUsedProductCollection($product)
							->addAttributeToSelect(array('price', 'special_price', 'special_from_date', 'special_to_date', 'tax_class_id'), 'left');

		Mage::getSingleton('core/resource_iterator')->walk($childProducts->getSelect(), array(array($this, 'SyncSKUData')),
			array(
				'parent_id' => $productData['entity_id'],
				'parent_product' => $product,
				'attributes' => $configurableAttributes,
				'db' => $db,
				'preparedStatement' => $insertSQL,
				'preparedskulinkStatement' => $insertSKULinkSQL,
				'preparedskumatrixStatement' => $insertSKUMatrixSQL,
				'preparedcategoryproductStatement' => $insertCategorySQL,
				'store' => $store )
		);

		$this->productsProcessed[] = $productData['entity_id'];

		if($productData['entity_id'] > $this->currentEntityId)
			$this->currentEntityId = $productData['entity_id'];
	}

	public function SyncGroupedProductData($args)
	{
		$productData = $args['row'];

		$store = $args['store'];
		$db = $args['db'];

		$insertSQL = $args['preparedskuStatement'];
		$insertSKULinkSQL = $args['preparedskulinkStatement'];
		$insertSKUMatrixSQL = $args['preparedskumatrixStatement'];

		$product = Mage::getModel('catalog/product')
					->setData($productData)
					->setStore($store)
					->setStoreId($store->getId())
					->setCustomerGroupId($this->ebayGroupId)
					->setIsSuperMode(true);

		$groupedData = Mage::getModel('catalog/product_type_grouped');

		$childProducts = $groupedData->getAssociatedProductCollection($product);
		$childProducts->addAttributeToSelect(array('sku', 'name', 'price', 'special_price', 'special_from_date', 'special_to_date'));

		$skulinkArgs = array();
		$skumatrixArgs = array();

		$minPrice = 0;

		$optionValues = array();

		foreach($childProducts as $childProduct)
		{
			$childProduct
				->setStore($store)
				->setStoreId($store->getId())
				->setCustomerGroupId($this->ebayGroupId)
				->setIsSuperMode(true);

			$price = $this->SyncProductPrice($store, $childProduct);

			if($minPrice == 0)
				$minPrice = $price;
			else
				$minPrice = min($minPrice, $price);

			$skulinkArgs[] = array($childProduct->getId(), $productData['entity_id'], $price);
			$skumatrixArgs[] = array($childProduct->getId(), $productData['entity_id'], '', 'Option', $childProduct->getName(), 0, 0);

			if(isset($optionValues[$childProduct->getName()]))
				$optionValues[$childProduct->getName()]++;
			else
				$optionValues[$childProduct->getName()] = 1;
		}

		foreach($optionValues as $key => $count)
		{
			if($count > 1)
			{
				$i = 0;

				foreach($childProducts as $childProduct)
				{
					if($childProduct->getName() == $key)
					{
						$skumatrixArg = &$skumatrixArgs[$i];
						$skumatrixArg[4] = $childProduct->getSku().' - '.$childProduct->getName();
					}

					$i++;
				}
			}
		}

		$productData['price'] = $minPrice;
		$productData['final_price'] = $minPrice;
		$productData['minimal_price'] = $minPrice;
		$productData['min_price'] = $minPrice;
		$productData['max_price'] = $minPrice;

		$this->SyncSimpleProductData(array_merge($args, array('row' => $productData)));

		for($i = 0; $i < count($skulinkArgs); $i++)
		{
			$insertSKULinkSQL->execute($skulinkArgs[$i]);
			$insertSKUMatrixSQL->execute($skumatrixArgs[$i]);
		}

		$this->productsProcessed[] = $productData['entity_id'];

		if($productData['entity_id'] > $this->currentEntityId)
			$this->currentEntityId = $productData['entity_id'];
	}

	public function SyncSimpleProductData($args)
	{
		$type = $args['type'];

		$db = $args['db'];

		$parentids;

		$store = $args['store'];
		$productData = $args['row'];

		$product_id = $productData['entity_id'];

		if(isset($args['preparedcheckproductStatement']))
		{
			$checkProductSQL = $args['preparedcheckproductStatement'];
			$checkProductSQL->execute(array($product_id));
			if($checkProductSQL->fetchColumn())
			{
				$checkProductSQL->closeCursor();
				return;
			}
			$checkProductSQL->closeCursor();
		}

		$product = Mage::getModel('catalog/product');
		$product->setData($productData)
				->setStore($store)
				->setStoreId($store->getId())
				->setCustomerGroupId($this->ebayGroupId)
				->setIsSuperMode(true);

		$stockItem = Mage::getModel('cataloginventory/stock_item');
		$stockItem->setStockId(Mage_CatalogInventory_Model_Stock::DEFAULT_STOCK_ID)
					->assignProduct($product);

		$insertSQL = $args['preparedStatement'];
		$insertCategorySQL = $args['preparedcategoryproductStatement'];
		$insertImageSQL = $args['preparedimageStatement'];
		$insertHTMLSQL = $args['preparedproducthtmlStatement'];
		$clearAttributeSQL = $args['preparedclearattributeStatement'];
		$insertAttributeSQL = $args['preparedattributeStatement'];
		$insertAttributeGroupSQL = $args['preparedattributegroupStatement'];
		$insertAttributeGroupMapSQL = $args['preparedattributegroupmapStatement'];
		$insertProductAttributeSQL = $args['preparedproductattributeStatement'];
		$clearProductQuestionSQL = $args['preparedclearproductquestionStatement'];
		$insertProductQuestionSQL = $args['preparedproductquestionStatement'];
		$insertProductAnswerSQL = $args['preparedproductanswerStatement'];

		$price = $this->SyncProductPrice($store, $product);

		$listPrice = $this->getExTaxPrice($product, $product->getPrice(), $store);
		if(!is_numeric($listPrice))
			$listPrice = $price;

		$qty = $stockItem->getQty();
		if(!is_numeric($qty))
			$qty = 0;

		// work around for description not appearing via collection
		if(!isset($productData['description']))
		{
			$description = Mage::getResourceSingleton('catalog/product')->getAttributeRawValue($product_id, 'description', $store->getId());
		}
		else
		{
			$description = $productData['description'];
		}

		$description = Mage::helper('codistosync')->processCmsContent($description);
		if($type == 'simple' &&
			$description == '')
		{
			if(!isset($parentids))
			{
				$configurableparentids = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($product_id);
				$groupedparentids = Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild($product_id);
				$bundleparentids = Mage::getModel('bundle/product_type')->getParentIdsByChild($product_id);

				$parentids = array_unique(array_merge($configurableparentids, $groupedparentids, $bundleparentids));
			}

			foreach ($parentids as $parentid) {

				$description = Mage::getResourceSingleton('catalog/product')->getAttributeRawValue($parentid, 'description', $store->getId());
				if($description)
				{
					$description = Mage::helper('codistosync')->processCmsContent($description);
					break;
				}

			}

			if(!$description)
				$description = '';
		}

		$productName = $productData['name'];
		if(!$productName)
			$productName = '';

		$data = array();

		$data[] = $product_id;
		$data[] = $type == 'configurable' ? 'c' : ($type == 'grouped' ? 'g' : 's');
		$data[] = $productData['sku'];
		$data[] = $productName;
		$data[] = $price;
		$data[] = $listPrice;
		$data[] = isset($productData['tax_class_id']) && $productData['tax_class_id'] ? $productData['tax_class_id'] : '';
		$data[] = $description;
		$data[] = $productData['status'] != 1 ? 0 : -1;
		$data[] = $stockItem->getManageStock() ? -1 : 0;
		$data[] = (int)$qty;
		$data[] = isset($productData['weight']) && is_numeric($productData['weight']) ? (float)$productData['weight'] : $productData['weight'];
		$data[] = $productData['codisto_in_store'];

		$insertSQL->execute($data);

		$categoryIds = $product->getCategoryIds();
		foreach ($categoryIds as $categoryId) {
			$insertCategorySQL->execute(array($product_id, $categoryId, 0));
		}

		if(isset($productData['short_description']) && strlen($productData['short_description']) > 0)
		{
			$shortDescription = Mage::helper('codistosync')->processCmsContent($productData['short_description']);

			$insertHTMLSQL->execute(array($product_id, 'Short Description', $shortDescription));
		}

		$clearAttributeSQL->execute(array($product_id));

		$attributeSet = array();
		$attributeCodes = array();
		$attributeTypes = array();
		$attributeCodeIDMap = array();

		$attributeSetID = $product->getAttributeSetId();
		if(isset($this->attributeCache[$attributeSetID]))
		{
			$attributes = $this->attributeCache[$attributeSetID];
		}
		else
		{
			$attributes = $product->getAttributes();

			$this->attributeCache[$attributeSetID] = $attributes;
		}

		foreach($attributes as $attribute)
		{
			$backend = $attribute->getBackEnd();
			if(!$backend->isStatic())
			{
				$attributeID = $attribute->getId();
				$attributeCode = $attribute->getAttributeCode();
				$attributeLabel = $attribute->getStoreLabel();
				$attributeTable = $backend->getTable();

				$attributeCodeIDMap[$attributeID] = $attributeCode;

				$attributeTypes[$attributeTable][$attributeID] = $attributeCode;

				if($attributeLabel)
				{
					$attributeGroupID = $attribute->getAttributeGroupId();
					$attributeGroupName = '';

					if($attributeGroupID)
					{
						if(isset($this->groupCache[$attributeGroupID]))
						{
							$attributeGroupName = $this->groupCache[$attributeGroupID];
						}
						else
						{
							$attributeGroup = Mage::getModel('catalog/product_attribute_group')->load($attributeGroupID);

							$attributeGroupName = html_entity_decode($attributeGroup->getAttributeGroupName());

							$this->groupCache[$attributeGroupID] = $attributeGroupName;
						}
					}

					$attributeFrontEnd = $attribute->getFrontend();

					$attributeData = array(
							'id' => $attributeID,
							'code' => $attributeCode,
							'name' => $attribute->getName(),
							'label' => $attributeLabel,
							'backend_type' => $attribute->getBackendType(),
							'frontend_type' => $attributeFrontEnd->getInputType(),
							'groupid' => $attributeGroupID,
							'groupname' => $attributeGroupName,
							'html' => ($attribute->getIsHtmlAllowedOnFront() && $attribute->getIsWysiwygEnabled()) ? true : false,
							'source_model' => $attribute->getSourceModel()
					);

					if(!isset($attributeData['frontend_type']) || is_null($attributeData['frontend_type']))
					{
						$attributeData['frontend_type'] = '';
					}

					if($attributeData['source_model'])
					{
						if(isset($this->optionCache[$store->getId().'-'.$attribute->getId()]))
						{
							$attributeData['source'] = $this->optionCache[$store->getId().'-'.$attribute->getId()];
						}
						else
						{
							$attributeData['source'] = $attribute->getSource();

							$this->optionCache[$store->getId().'-'.$attribute->getId()] = $attributeData['source'];
						}
					}
					else
					{
						$attributeData['source'] = $attribute->getSource();
					}

					$attributeSet[] = $attributeData;
					$attributeCodes[] = $attributeCode;
				}
			}

		}

		$adapter = Mage::getModel('core/resource')->getConnection(Mage_Core_Model_Resource::DEFAULT_READ_RESOURCE);

		$attrTypeSelects = array();

		foreach ($attributeTypes as $table => $_attributes)
		{
			$attrTypeSelect = $adapter->select()
						->from(array('default_value' => $table), array('attribute_id'))
						->where('default_value.attribute_id IN (?)', array_keys($_attributes))
						->where('default_value.entity_type_id = :entity_type_id')
						->where('default_value.entity_id = :entity_id')
						->where('default_value.store_id = 0');


			if($store->getId() == Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID)
			{
				$attrTypeSelect->columns(array('attr_value' => new Zend_Db_Expr('CAST(value AS CHAR)')), 'default_value');
				$attrTypeSelect->where('default_value.value IS NOT NULL');
			}
			else
			{
				$attrTypeSelect->joinLeft(
					array('store_value' => $table),
					'store_value.attribute_id = default_value.attribute_id AND store_value.entity_type_id = default_value.entity_type_id AND store_value.entity_id = default_value.entity_id AND store_value.store_id = :store_id ',
					array('attr_value' => new Zend_Db_Expr('CAST(COALESCE(store_value.value, default_value.value) AS CHAR)'))
				);
				$attrTypeSelect->where('store_value.value IS NOT NULL OR default_value.value IS NOT NULL');
			}

			$attrTypeSelects[] = $attrTypeSelect;
		}

		$attributeValues = array();

		$attrSelect = $adapter->select()->union($attrTypeSelects, Zend_Db_Select::SQL_UNION_ALL);

		$attrArgs = array(
			'entity_type_id' => 4,
			'entity_id' => $product_id,
			'store_id' => $store->getId()
		);

		$attributeRows = $adapter->fetchPairs($attrSelect, $attrArgs);
		foreach ($attributeRows as $attributeId => $attributeValue)
		{
			$attributeCode = $attributeCodeIDMap[$attributeId];
			$attributeValues[$attributeCode] = $attributeValue;
		}

		foreach($attributeSet as $attributeData)
		{
			if(isset($attributeValues[$attributeData['code']]))
				$attributeValue = $attributeValues[$attributeData['code']];
			else
				$attributeValue = null;

			if(isset($attributeData['source']) &&
				$attributeData['source_model'] == 'eav/entity_attribute_source_boolean')
			{
				$attributeData['backend_type'] = 'boolean';

				if(isset($attributeValue) && $attributeValue)
					$attributeValue = -1;
				else
					$attributeValue = 0;
			}

			else if($attributeData['html'])
			{
				$attributeValue = Mage::helper('codistosync')->processCmsContent($attributeValue);
			}

			else if( in_array($attributeData['frontend_type'], array( 'select', 'multiselect' ) ) )
			{
				if(is_array($attributeValue))
				{
					$attributeValueSet = array();

					foreach($attributeValue as $attributeOptionId)
					{
						if(isset($this->optionTextCache[$store->getId().'-'.$attributeData['id'].'-'.$attributeOptionId]))
						{
							$attributeValueSet[] = $this->optionTextCache[$store->getId().'-'.$attributeData['id'].'-'.$attributeOptionId];
						}
						else
						{
							try
							{
								$attributeText = $attributeData['source']->getOptionText($attributeOptionId);

								$this->optionTextCache[$store->getId().'-'.$attributeData['id'].'-'.$attributeOptionId] = $attributeText;

								$attributeValueSet[] = $attributeText;
							}
							catch(Exception $e)
							{

							}
						}
					}

					$attributeValue = $attributeValueSet;
				}
				else
				{
					if(isset($this->optionTextCache[$store->getId().'-'.$attributeData['id'].'-'.$attributeValue]))
					{
						$attributeValue = $this->optionTextCache[$store->getId().'-'.$attributeData['id'].'-'.$attributeValue];
					}
					else
					{
						try
						{
							$attributeText = $attributeData['source']->getOptionText($attributeValue);

							$this->optionTextCache[$store->getId().'-'.$attributeData['id'].'-'.$attributeValue] = $attributeText;

							$attributeValue = $attributeText;
						}
						catch(Exception $e)
						{
							$attributeValue = null;
						}
					}
				}
			}

			if(isset($attributeValue) && !is_null($attributeValue))
			{
				if($attributeData['html'])
				{
					$insertHTMLSQL->execute(array($product_id, $attributeData['label'], $attributeValue));
				}

				$insertAttributeSQL->execute(array($attributeData['id'], $attributeData['name'], $attributeData['label'], $attributeData['backend_type'], $attributeData['frontend_type']));

				if($attributeData['groupid'])
				{
					$insertAttributeGroupSQL->execute(array($attributeData['groupid'], $attributeData['groupname']));
					$insertAttributeGroupMapSQL->execute(array($attributeData['groupid'], $attributeData['id']));
				}

				if(is_array($attributeValue))
					$attributeValue = implode(',', $attributeValue);

				$insertProductAttributeSQL->execute(array($product_id, $attributeData['id'], $attributeValue));
			}
		}

		$hasImage = false;
		$product->load('media_gallery');

		$primaryImage = isset($productData['image']) ? $productData['image'] : '';

		foreach ($product->getMediaGallery('images') as $image) {

			$imgURL = $product->getMediaConfig()->getMediaUrl($image['file']);

			$enabled = ($image['disabled'] == 0 ? -1 : 0);

			if($image['file'] == $primaryImage)
			{
				$tag = '';
				$sequence = 0;
			}
			else
			{
				$tag = $image['label'];
				if(!$tag)
					$tag = '';
				$sequence = $image['position'];
				if(!$sequence)
					$sequence = 1;
				else
					$sequence++;
			}

			$insertImageSQL->execute(array($product_id, $imgURL, $tag, $sequence, $enabled));

			$hasImage = true;

		}

		if($type == 'simple' &&
			!$hasImage)
		{
			$mediaAttributeModel = Mage::getResourceSingleton('catalog/product_attribute_backend_media');
			$mediaConfig = Mage::getSingleton('catalog/product_media_config');

			$baseSequence = 0;

			if(!isset($parentids))
			{
				$configurableparentids = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($product_id);
				$groupedparentids = Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild($product_id);
				$bundleparentids = Mage::getModel('bundle/product_type')->getParentIdsByChild($product_id);

				$parentids = array_unique(array_merge($configurableparentids, $groupedparentids, $bundleparentids));
			}

			foreach ($parentids as $parentid) {

				$baseImagePath = Mage::getResourceSingleton('catalog/product')->getAttributeRawValue($parentid, 'image', $store->getId());

				if(method_exists($mediaAttributeModel, 'loadGallerySet'))
				{
					$mediaGallery = $mediaAttributeModel->loadGallerySet(array($parentid), $store->getId());
				}
				else
				{
					$parentProduct = Mage::getModel('catalog/product')
										->setData(array('entity_id' => $parentid, 'type_id' => 'simple' ));

					$attributes = $parentProduct->getTypeInstance(true)->getSetAttributes($parentProduct);
					$media_gallery = $attributes['media_gallery'];
					$backend = $media_gallery->getBackend();
					$backend->afterLoad($parentProduct);

					$mediaGallery = $parentProduct->getMediaGallery('images');
				}

				$maxSequence = 0;
				$baseImageFound = false;

				foreach ($mediaGallery as $image) {

					$imgURL = $mediaConfig->getMediaUrl($image['file']);

					$enabled = ($image['disabled'] == 0 ? -1 : 0);

					if(!$baseImageFound && ($image['file'] == $baseImagePath))
					{
						$tag = '';
						$sequence = 0;
						$baseImageFound = true;
					}
					else
					{
						$tag = $image['label'];
						if(!$tag)
							$tag = '';
						$sequence = $image['position'];
						if(!$sequence)
							$sequence = 1;
						else
							$sequence++;

						$sequence += $baseSequence;

						$maxSequence = max($sequence, $maxSequence);
					}

					$insertImageSQL->execute(array($product_id, $imgURL, $tag, $sequence, $enabled));
				}

				$baseSequence = $maxSequence;

				if($baseImageFound)
					break;
			}
		}


		// process simple product question/answers
		$clearProductQuestionSQL->execute(array($product_id));

		$options = $product->getProductOptionsCollection();

		foreach($options as $option)
		{
			$optionId = $option->getOptionId();
			$optionName = $option->getTitle();
			$optionType = $option->getType();
			$optionSortOrder = $option->getSortOrder();

			if($optionId && $optionName)
			{
				if(!$optionType)
					$optionType = '';

				if(!$optionSortOrder)
					$optionSortOrder = 0;

				$insertProductQuestionSQL->execute(array($optionId, $product_id, $optionName, $optionType, $optionSortOrder));

				$values = $option->getValuesCollection();

				foreach($values as $value)
				{
					$valueName = $value->getTitle();
					if(!$valueName)
						$valueName = '';

					$valuePriceModifier = '';
					if($value->getPriceType() == 'fixed')
					{
						$valuePriceModifier = 'Price + '.$value->getPrice();
					}

					if($value->getPriceType() == 'percent')
					{
						$valuePriceModifier = 'Price * '.($value->getPrice() / 100.0);
					}

					$valueSkuModifier = $value->getSku();
					if(!$valueSkuModifier)
						$valueSkuModifier = '';

					$valueSortOrder = $value->getSortOrder();
					if(!$valueSortOrder)
						$valueSortOrder = 0;

					$insertProductAnswerSQL->execute(array($optionId, $valueName, $valuePriceModifier, $valueSkuModifier, $valueSortOrder));
				}
			}
		}

		if($type == 'simple')
		{
			$this->productsProcessed[] = $product_id;

			if($product_id > $this->currentEntityId)
				$this->currentEntityId = $product_id;
		}
	}

	public function SyncOrderData($args)
	{
		$insertOrdersSQL = $args['preparedStatement'];

		$orderData = $args['row'];

		$insertOrdersSQL->execute(array($orderData['codisto_orderid'], ($orderData['status'])?$orderData['status']:'processing', $orderData['pay_date'], $orderData['ship_date'], $orderData['carrier'], $orderData['track_number']));

		$this->ordersProcessed[] = $orderData['entity_id'];
		$this->currentEntityId = $orderData['entity_id'];
	}

	public function SyncIncrementalStores($storeId)
	{
		$helper = Mage::helper('codistosync');

		$syncDbPath = $helper->getSyncPath('sync-'.$storeId.'.db');

		$syncDb = null;

		if(file_exists($syncDbPath))
		{
			$syncDb = $this->GetSyncDb($syncDbPath);
		}

		return array( 'id' => $storeId, 'path' => $syncDbPath, 'db' => $syncDb );
	}

	public function SyncIncremental($simpleCount, $configurableCount)
	{
		$coreResource = Mage::getSingleton('core/resource');
		$adapter = $coreResource->getConnection(Mage_Core_Model_Resource::DEFAULT_WRITE_RESOURCE);

		$tablePrefix = Mage::getConfig()->getTablePrefix();

		$storeName = $coreResource->getTableName('core/store');

		$storeIds = array( 0 );

		$defaultMerchantList = Mage::getStoreConfig('codisto/merchantid', 0);

		$stores = $adapter->fetchCol('SELECT store_id FROM `'.$storeName.'`');

		foreach($stores as $storeId)
		{
			$storeMerchantList = Mage::getStoreConfig('codisto/merchantid', $storeId);
			if($storeMerchantList && $storeMerchantList != $defaultMerchantList)
			{
				$storeIds[] = $storeId;
			}
		}

		$stores = array_map( array($this, 'SyncIncrementalStores'), $storeIds );

		$productUpdateEntries = $adapter->fetchPairs('SELECT product_id, stamp FROM `'.$tablePrefix.'codisto_product_change` ORDER BY product_id LIMIT '.(int)$simpleCount);
		$categoryUpdateEntries = $adapter->fetchPairs('SELECT category_id, stamp FROM `'.$tablePrefix.'codisto_category_change` ORDER BY category_id');
		$orderUpdateEntries = $adapter->fetchPairs('SELECT order_id, stamp FROM `'.$tablePrefix.'codisto_order_change` ORDER BY order_id LIMIT 1000');

		if(empty($productUpdateEntries) &&
			empty($categoryUpdateEntries) &&
			empty($orderUpdateEntries))
		{
			return 'nochange';
		}

		$productUpdateIds = array_keys($productUpdateEntries);
		$categoryUpdateIds = array_keys($categoryUpdateEntries);
		$orderUpdateIds = array_keys($orderUpdateEntries);

		$coreResource = Mage::getSingleton('core/resource');

		$catalogWebsiteName = $coreResource->getTableName('catalog/product_website');
		$storeName = $coreResource->getTableName('core/store');

		$this->productsProcessed = array();
		$this->ordersProcessed = array();

		foreach($stores as $store)
		{
			if($store['db'] != null)
			{
				$storeId = $store['id'];

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
						{
							$storeId = $firstStore->getId();
						}
					}
				}

				$storeObject = Mage::app()->getStore($storeId);

				Mage::app()->setCurrentStore($storeObject);

				$db = $store['db'];

				$db->exec('BEGIN EXCLUSIVE TRANSACTION');

				if(!empty($productUpdateIds))
				{
					$db->exec(
						'DELETE FROM Product WHERE ExternalReference IN ('.implode(',', $productUpdateIds).');'.
						'DELETE FROM ProductImage WHERE ProductExternalReference IN ('.implode(',', $productUpdateIds).');'.
						'DELETE FROM ProductHTML WHERE ProductExternalReference IN ('.implode(',', $productUpdateIds).');'.
						'DELETE FROM SKULink WHERE ProductExternalReference IN ('.implode(',', $productUpdateIds).');'.
						'DELETE FROM SKUMatrix WHERE ProductExternalReference IN ('.implode(',', $productUpdateIds).');'.
						'DELETE FROM SKU WHERE ProductExternalReference IN ('.implode(',', $productUpdateIds).');'.
						'DELETE FROM CategoryProduct WHERE ProductExternalReference IN ('.implode(',', $productUpdateIds).')'
					);

					$insertCategoryProduct = $db->prepare('INSERT OR IGNORE INTO CategoryProduct(ProductExternalReference, CategoryExternalReference, Sequence) VALUES(?,?,?)');
					$insertProduct = $db->prepare('INSERT INTO Product(ExternalReference, Type, Code, Name, Price, ListPrice, TaxClass, Description, Enabled, StockControl, StockLevel, Weight, InStore) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
					$checkProduct = $db->prepare('SELECT CASE WHEN EXISTS(SELECT 1 FROM Product WHERE ExternalReference = ?) THEN 1 ELSE 0 END');
					$insertSKU = $db->prepare('INSERT OR IGNORE INTO SKU(ExternalReference, Code, ProductExternalReference, Name, StockControl, StockLevel, Price, Enabled, InStore) VALUES(?,?,?,?,?,?,?,?,?)');
					$insertSKULink = $db->prepare('INSERT OR REPLACE INTO SKULink (SKUExternalReference, ProductExternalReference, Price) VALUES (?, ?, ?)');
					$insertSKUMatrix = $db->prepare('INSERT INTO SKUMatrix(SKUExternalReference, ProductExternalReference, Code, OptionName, OptionValue, ProductOptionExternalReference, ProductOptionValueExternalReference) VALUES(?,?,?,?,?,?,?)');
					$insertImage = $db->prepare('INSERT INTO ProductImage(ProductExternalReference, URL, Tag, Sequence, Enabled) VALUES(?,?,?,?,?)');
					$insertProductOptionValue = $db->prepare('INSERT INTO ProductOptionValue (ExternalReference, Sequence) VALUES (?,?)');
					$insertProductHTML = $db->prepare('INSERT OR IGNORE INTO ProductHTML(ProductExternalReference, Tag, HTML) VALUES (?, ?, ?)');
					$clearAttribute = $db->prepare('DELETE FROM ProductAttributeValue WHERE ProductExternalReference = ?');
					$insertAttribute = $db->prepare('INSERT OR REPLACE INTO Attribute(ID, Code, Label, Type, Input) VALUES (?, ?, ?, ?, ?)');
					$insertAttributeGroup = $db->prepare('INSERT OR REPLACE INTO AttributeGroup(ID, Name) VALUES(?, ?)');
					$insertAttributeGroupMap = $db->prepare('INSERT OR IGNORE INTO AttributeGroupMap(GroupID, AttributeID) VALUES(?,?)');
					$insertProductAttribute = $db->prepare('INSERT OR IGNORE INTO ProductAttributeValue(ProductExternalReference, AttributeID, Value) VALUES (?, ?, ?)');
					$clearProductQuestion = $db->prepare('DELETE FROM ProductQuestionAnswer WHERE ProductQuestionExternalReference IN (SELECT ExternalReference FROM ProductQuestion WHERE ProductExternalReference = ?1); DELETE FROM ProductQuestion WHERE ProductExternalReference = ?1');
					$insertProductQuestion = $db->prepare('INSERT OR REPLACE INTO ProductQuestion(ExternalReference, ProductExternalReference, Name, Type, Sequence) VALUES (?, ?, ?, ?, ?)');
					$insertProductAnswer = $db->prepare('INSERT INTO ProductQuestionAnswer(ProductQuestionExternalReference, Value, PriceModifier, SKUModifier, Sequence) VALUES (?, ?, ?, ?, ?)');

					// Simple Products not participating as configurable skus
					$simpleProducts = $this->getProductCollection()
										->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'special_from_date', 'special_to_date', 'status', 'tax_class_id', 'weight'), 'left')
										->addAttributeToFilter('type_id', array('eq' => 'simple'))
										->addAttributeToFilter('entity_id', array('in' => $productUpdateIds) );
					$simpleProducts->getSelect()
										->columns(array('codisto_in_store' => new Zend_Db_Expr('CASE WHEN `e`.entity_id IN (SELECT product_id FROM `'.$catalogWebsiteName.'` WHERE website_id IN (SELECT website_id FROM `'.$storeName.'` WHERE store_id = '.$storeId.' OR EXISTS(SELECT 1 FROM `'.$storeName.'` WHERE store_id = '.$storeId.' AND website_id = 0))) THEN -1 ELSE 0 END')));

					Mage::getSingleton('core/resource_iterator')->walk($simpleProducts->getSelect(), array(array($this, 'SyncSimpleProductData')),
						array(
							'type' => 'simple',
							'db' => $db,
							'preparedStatement' => $insertProduct,
							'preparedcategoryproductStatement' => $insertCategoryProduct,
							'preparedimageStatement' => $insertImage,
							'preparedproducthtmlStatement' => $insertProductHTML,
							'preparedclearattributeStatement' => $clearAttribute,
							'preparedattributeStatement' => $insertAttribute,
							'preparedattributegroupStatement' => $insertAttributeGroup,
							'preparedattributegroupmapStatement' => $insertAttributeGroupMap,
							'preparedproductattributeStatement' => $insertProductAttribute,
							'preparedclearproductquestionStatement' => $clearProductQuestion,
							'preparedproductquestionStatement' => $insertProductQuestion,
							'preparedproductanswerStatement' => $insertProductAnswer,
							'store' => $storeObject ));

					// Configurable products
					$configurableProducts = $this->getProductCollection()
										->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'special_from_date', 'special_to_date', 'status', 'tax_class_id', 'weight'), 'left')
										->addAttributeToFilter('type_id', array('eq' => 'configurable'))
										->addAttributeToFilter('entity_id', array('in' => $productUpdateIds));
					$configurableProducts->getSelect()
												->columns(array('codisto_in_store' => new Zend_Db_Expr('CASE WHEN `e`.entity_id IN (SELECT product_id FROM `'.$catalogWebsiteName.'` WHERE website_id IN (SELECT website_id FROM `'.$storeName.'` WHERE store_id = '.$storeId.' OR EXISTS(SELECT 1 FROM `'.$storeName.'` WHERE store_id = '.$storeId.' AND website_id = 0))) THEN -1 ELSE 0 END')));

					Mage::getSingleton('core/resource_iterator')->walk($configurableProducts->getSelect(), array(array($this, 'SyncConfigurableProductData')),
						array(
							'type' => 'configurable',
							'db' => $db,
							'preparedStatement' => $insertProduct,
							'preparedskuStatement' => $insertSKU,
							'preparedskulinkStatement' => $insertSKULink,
							'preparedskumatrixStatement' => $insertSKUMatrix,
							'preparedcategoryproductStatement' => $insertCategoryProduct,
							'preparedimageStatement' => $insertImage,
							'preparedproducthtmlStatement' => $insertProductHTML,
							'preparedclearattributeStatement' => $clearAttribute,
							'preparedattributeStatement' => $insertAttribute,
							'preparedattributegroupStatement' => $insertAttributeGroup,
							'preparedattributegroupmapStatement' => $insertAttributeGroupMap,
							'preparedproductattributeStatement' => $insertProductAttribute,
							'preparedclearproductquestionStatement' => $clearProductQuestion,
							'preparedproductquestionStatement' => $insertProductQuestion,
							'preparedproductanswerStatement' => $insertProductAnswer,
							'store' => $storeObject )
					);

					// Grouped products
					$groupedProducts = $this->getProductCollection()
										->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'special_from_date', 'special_to_date', 'status', 'tax_class_id', 'weight'), 'left')
										->addAttributeToFilter('type_id', array('eq' => 'grouped'))
										->addAttributeToFilter('entity_id', array('in' => $productUpdateIds ));

					$groupedProducts->getSelect()
												->columns(array('codisto_in_store' => new Zend_Db_Expr('CASE WHEN `e`.entity_id IN (SELECT product_id FROM `'.$catalogWebsiteName.'` WHERE website_id IN (SELECT website_id FROM `'.$storeName.'` WHERE store_id = '.$storeId.' OR EXISTS(SELECT 1 FROM `'.$storeName.'` WHERE store_id = '.$storeId.' AND website_id = 0))) THEN -1 ELSE 0 END')));

					Mage::getSingleton('core/resource_iterator')->walk($groupedProducts->getSelect(), array(array($this, 'SyncGroupedProductData')),
						array(
							'type' => 'grouped',
							'db' => $db,
							'preparedStatement' => $insertProduct,
							'preparedskuStatement' => $insertSKU,
							'preparedskulinkStatement' => $insertSKULink,
							'preparedskumatrixStatement' => $insertSKUMatrix,
							'preparedcategoryproductStatement' => $insertCategoryProduct,
							'preparedimageStatement' => $insertImage,
							'preparedproducthtmlStatement' => $insertProductHTML,
							'preparedclearattributeStatement' => $clearAttribute,
							'preparedattributeStatement' => $insertAttribute,
							'preparedattributegroupStatement' => $insertAttributeGroup,
							'preparedattributegroupmapStatement' => $insertAttributeGroupMap,
							'preparedproductattributeStatement' => $insertProductAttribute,
							'preparedclearproductquestionStatement' => $clearProductQuestion,
							'preparedproductquestionStatement' => $insertProductQuestion,
							'preparedproductanswerStatement' => $insertProductAnswer,
							'store' => $storeObject )
					);
				}

				if(!empty($categoryUpdateIds))
				{
					$insertCategory = $db->prepare('INSERT OR REPLACE INTO Category(ExternalReference, Name, ParentExternalReference, LastModified, Enabled, Sequence) VALUES(?,?,?,?,?,?)');

					// Categories
					$categories = Mage::getModel('catalog/category', array('disable_flat' => true))->getCollection()
										->addAttributeToSelect(array('name', 'image', 'is_active', 'updated_at', 'parent_id', 'position'), 'left')
										->addAttributeToFilter('entity_id', array('in' => $categoryUpdateIds ));

					Mage::getSingleton('core/resource_iterator')->walk($categories->getSelect(), array(array($this, 'SyncCategoryData')), array( 'db' => $db, 'preparedStatement' => $insertCategory, 'store' => $storeObject ));
				}

				if(!empty($orderUpdateIds))
				{
					$insertOrders = $db->prepare('INSERT OR REPLACE INTO [Order] (ID, Status, PaymentDate, ShipmentDate, Carrier, TrackingNumber) VALUES (?, ?, ?, ?, ?, ?)');

					$orderStoreId = $storeId;
					if($storeId == 0)
					{
						$firstStore = Mage::getModel('core/store')->getCollection()
									->addFieldToFilter('is_active', array('neq' => 0))
									->addFieldToFilter('store_id', array( 'gt' => 0))
									->setOrder('store_id', 'ASC');
						$firstStore->setPageSize(1)->setCurPage(1);
						$orderStoreId = $firstStore->getFirstItem()->getId();
					}

					$invoiceName = $coreResource->getTableName('sales/invoice');
					$shipmentName = $coreResource->getTableName('sales/shipment');
					$shipmentTrackName = $coreResource->getTableName('sales/shipment_track');

					$ts = Mage::getModel('core/date')->gmtTimestamp();
					$ts -= 7776000; // 90 days

					$orders = Mage::getModel('sales/order')->getCollection()
								->addFieldToSelect(array('codisto_orderid', 'status'))
								->addAttributeToFilter('entity_id', array('in' => $orderUpdateIds ))
								->addAttributeToFilter('main_table.store_id', array('eq' => $orderStoreId ))
								->addAttributeToFilter('main_table.updated_at', array('gteq' => date('Y-m-d H:i:s', $ts)))
								->addAttributeToFilter('main_table.codisto_orderid', array('notnull' => true));
					$orders->getSelect()->joinLeft( array('i' => $invoiceName), 'i.order_id = main_table.entity_id AND i.state = 2', array('pay_date' => 'MIN(i.created_at)'));
					$orders->getSelect()->joinLeft( array('s' => $shipmentName), 's.order_id = main_table.entity_id', array('ship_date' => 'MIN(s.created_at)'));
					$orders->getSelect()->joinLeft( array('t' => $shipmentTrackName), 't.order_id = main_table.entity_id', array('carrier' => 'GROUP_CONCAT(COALESCE(t.title, \'\') SEPARATOR \',\')', 'track_number' => 'GROUP_CONCAT(COALESCE(t.track_number, \'\') SEPARATOR \',\')'));
					$orders->getSelect()->group(array('main_table.entity_id', 'main_table.codisto_orderid', 'main_table.status'));
					$orders->setOrder('entity_id', 'ASC');

					Mage::getSingleton('core/resource_iterator')->walk($orders->getSelect(), array(array($this, 'SyncOrderData')),
						array(
							'db' => $db,
							'preparedStatement' => $insertOrders,
							'store' => $storeObject )
					);
				}

				$uniqueId = uniqid();

				$adapter->beginTransaction();
				try
				{
					$adapter->query('REPLACE INTO `'.$tablePrefix.'codisto_sync` ( store_id, token ) VALUES ('.$storeId.', \''.$uniqueId.'\')');
				}
				catch(Exception $e)
				{
					$adapter->query('CREATE TABLE `'.$tablePrefix.'codisto_sync` (store_id smallint(5) unsigned PRIMARY KEY NOT NULL, token varchar(20) NOT NULL)');
					$adapter->insert($tablePrefix.'codisto_sync', array( 'token' => $uniqueId, 'store_id' => $storeId ));
				}
				$adapter->commit();

				$db->exec('CREATE TABLE IF NOT EXISTS Sync (token text NOT NULL, sentinel NOT NULL PRIMARY KEY DEFAULT 1, CHECK(sentinel = 1))');
				$db->exec('INSERT OR REPLACE INTO Sync (token) VALUES (\''.$uniqueId.'\')');

				if(!empty($productUpdateIds))
				{
					$db->exec('CREATE TABLE IF NOT EXISTS ProductChange (ExternalReference text NOT NULL PRIMARY KEY, stamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP)');
					foreach($productUpdateIds as $updateId)
					{
						$db->exec('INSERT OR REPLACE INTO ProductChange (ExternalReference) VALUES ('.$updateId.')');
					}
				}

				if(!empty($categoryUpdateIds))
				{
					$db->exec('CREATE TABLE IF NOT EXISTS CategoryChange (ExternalReference text NOT NULL PRIMARY KEY, stamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP)');
					foreach($categoryUpdateIds as $updateId)
					{
						$db->exec('INSERT OR REPLACE INTO CategoryChange (ExternalReference) VALUES ('.$updateId.')');
					}
				}

				if(!empty($orderUpdateIds))
				{
					$db->exec('CREATE TABLE IF NOT EXISTS OrderChange (ExternalReference text NOT NULL PRIMARY KEY, stamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP)');
					foreach($orderUpdateIds as $updateId)
					{
						$db->exec('INSERT OR REPLACE INTO OrderChange (ExternalReference) VALUES ('.$updateId.')');
					}
				}

				$db->exec('COMMIT TRANSACTION');
			}
		}

		if(!empty($productUpdateEntries))
		{
			$adapter->query('CREATE TEMPORARY TABLE tmp_codisto_change (product_id int(10) unsigned, stamp datetime)');
			foreach($productUpdateEntries as $product_id => $stamp)
			{
				$adapter->insert('tmp_codisto_change', array( 'product_id' => $product_id, 'stamp' => $stamp ) );
			}
			$adapter->query('DELETE FROM `'.$tablePrefix.'codisto_product_change` '.
							'WHERE EXISTS ('.
								'SELECT 1 FROM tmp_codisto_change '.
								'WHERE product_id = `'.$tablePrefix.'codisto_product_change`.product_id AND '.
									'stamp = `'.$tablePrefix.'codisto_product_change`.stamp'.
							')');
			$adapter->query('DROP TABLE tmp_codisto_change');
		}

		if(!empty($categoryUpdateEntries))
		{
			$adapter->query('CREATE TEMPORARY TABLE tmp_codisto_change (category_id int(10) unsigned, stamp datetime)');
			foreach($categoryUpdateEntries as $category_id => $stamp)
			{
				$adapter->insert('tmp_codisto_change', array( 'category_id' => $category_id, 'stamp' => $stamp ) );
			}
			$adapter->query('DELETE FROM `'.$tablePrefix.'codisto_category_change` '.
							'WHERE EXISTS ('.
								'SELECT 1 FROM tmp_codisto_change '.
								'WHERE category_id = `'.$tablePrefix.'codisto_category_change`.category_id AND '.
									'stamp = `'.$tablePrefix.'codisto_category_change`.stamp'.
							')');
			$adapter->query('DROP TABLE tmp_codisto_change');
		}

		if(!empty($orderUpdateEntries))
		{
			$adapter->query('CREATE TEMPORARY TABLE tmp_codisto_change (order_id int(10) unsigned, stamp datetime)');
			foreach($orderUpdateEntries as $order_id => $stamp)
			{
				$adapter->insert('tmp_codisto_change', array( 'order_id' => $order_id, 'stamp' => $stamp ) );
			}
			$adapter->query('DELETE FROM `'.$tablePrefix.'codisto_order_change` '.
							'WHERE EXISTS ('.
								'SELECT 1 FROM tmp_codisto_change '.
								'WHERE order_id = `'.$tablePrefix.'codisto_order_change`.order_id AND '.
									'stamp = `'.$tablePrefix.'codisto_order_change`.stamp'.
							')');
			$adapter->query('DROP TABLE tmp_codisto_change');
		}

		return $adapter->fetchOne('SELECT CASE WHEN '.
								'EXISTS(SELECT 1 FROM `'.$tablePrefix.'codisto_product_change`) OR '.
								'EXISTS(SELECT 1 FROM `'.$tablePrefix.'codisto_category_change`) OR '.
								'EXISTS(SELECT 1 FROM `'.$tablePrefix.'codisto_order_change`) '.
								'THEN \'pending\' ELSE \'complete\' END');
	}

	public function SyncChangeComplete($syncDb, $changeDb, $storeId)
	{
		$db = $this->GetSyncDb($syncDb);

		$db->exec('ATTACH DATABASE \''.$changeDb.'\' AS ChangeDb');

		$qry = $db->query('SELECT CASE WHEN '.
							'EXISTS(SELECT 1 FROM sqlite_master WHERE type COLLATE NOCASE = \'TABLE\' AND name = \'ProductChange\') AND '.
							'EXISTS(SELECT 1 FROM ChangeDb.sqlite_master WHERE type COLLATE NOCASE = \'TABLE\' AND name = \'ProductChangeProcessed\') '.
							'THEN -1 ELSE 0 END');
		$processProductChange = $qry->fetchColumn();
		$qry->closeCursor();

		$qry = $db->query('SELECT CASE WHEN '.
							'EXISTS(SELECT 1 FROM sqlite_master WHERE type COLLATE NOCASE = \'TABLE\' AND name = \'CategoryChange\') AND '.
							'EXISTS(SELECT 1 FROM ChangeDb.sqlite_master WHERE type COLLATE NOCASE = \'TABLE\' AND name = \'CategoryChangeProcessed\') '.
							'THEN -1 ELSE 0 END');
		$processCategoryChange = $qry->fetchColumn();
		$qry->closeCursor();

		$qry = $db->query('SELECT CASE WHEN '.
							'EXISTS(SELECT 1 FROM sqlite_master WHERE type COLLATE NOCASE = \'TABLE\' AND name = \'OrderChange\') AND '.
							'EXISTS(SELECT 1 FROM ChangeDb.sqlite_master WHERE type COLLATE NOCASE = \'TABLE\' AND name = \'OrderChangeProcessed\') '.
							'THEN -1 ELSE 0 END');
		$processOrderChange = $qry->fetchColumn();
		$qry->closeCursor();

		if($processProductChange)
		{
			$db->exec('DELETE FROM ProductChange '.
						'WHERE EXISTS('.
							'SELECT 1 FROM ProductChangeProcessed '.
							'WHERE ExternalReference = ProductChange.ExternalReference AND '.
								'stamp = ProductChange.stamp'.
						')');
		}

		if($processCategoryChange)
		{
			$db->exec('DELETE FROM CategoryChange '.
						'WHERE EXISTS('.
							'SELECT 1 FROM CategoryChangeProcessed '.
							'WHERE ExternalReference = CategoryChange.ExternalReference AND '.
								'stamp = CategoryChange.stamp'.
						')');
		}

		if($processOrderChange)
		{
			$db->exec('DELETE FROM OrderChange '.
						'WHERE EXISTS('.
							'SELECT 1 FROM OrderChangeProcessed '.
							'WHERE ExternalReference = OrderChange.ExternalReference AND '.
								'stamp = OrderChange.stamp'.
						')');
		}
	}

	public function SyncChunk($syncDb, $simpleCount, $configurableCount, $storeId, $first)
	{
		$store = Mage::app()->getStore($storeId);

		$db = $this->GetSyncDb($syncDb);

		$insertCategory = $db->prepare('INSERT OR REPLACE INTO Category(ExternalReference, Name, ParentExternalReference, LastModified, Enabled, Sequence) VALUES(?,?,?,?,?,?)');
		$insertCategoryProduct = $db->prepare('INSERT OR IGNORE INTO CategoryProduct(ProductExternalReference, CategoryExternalReference, Sequence) VALUES(?,?,?)');
		$insertProduct = $db->prepare('INSERT INTO Product(ExternalReference, Type, Code, Name, Price, ListPrice, TaxClass, Description, Enabled, StockControl, StockLevel, Weight, InStore) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
		$checkProduct = $db->prepare('SELECT CASE WHEN EXISTS(SELECT 1 FROM Product WHERE ExternalReference = ?) THEN 1 ELSE 0 END');
		$insertSKU = $db->prepare('INSERT OR IGNORE INTO SKU(ExternalReference, Code, ProductExternalReference, Name, StockControl, StockLevel, Price, Enabled, InStore) VALUES(?,?,?,?,?,?,?,?,?)');
		$insertSKULink = $db->prepare('INSERT OR REPLACE INTO SKULink (SKUExternalReference, ProductExternalReference, Price) VALUES (?, ?, ?)');
		$insertSKUMatrix = $db->prepare('INSERT INTO SKUMatrix(SKUExternalReference, ProductExternalReference, Code, OptionName, OptionValue, ProductOptionExternalReference, ProductOptionValueExternalReference) VALUES(?,?,?,?,?,?,?)');
		$insertImage = $db->prepare('INSERT INTO ProductImage(ProductExternalReference, URL, Tag, Sequence, Enabled) VALUES(?,?,?,?,?)');
		$insertProductOptionValue = $db->prepare('INSERT INTO ProductOptionValue (ExternalReference, Sequence) VALUES (?,?)');
		$insertProductHTML = $db->prepare('INSERT OR IGNORE INTO ProductHTML(ProductExternalReference, Tag, HTML) VALUES (?, ?, ?)');
		$clearAttribute = $db->prepare('DELETE FROM ProductAttributeValue WHERE ProductExternalReference = ?');
		$insertAttribute = $db->prepare('INSERT OR REPLACE INTO Attribute(ID, Code, Label, Type, Input) VALUES (?, ?, ?, ?, ?)');
		$insertAttributeGroup = $db->prepare('INSERT OR REPLACE INTO AttributeGroup(ID, Name) VALUES(?, ?)');
		$insertAttributeGroupMap = $db->prepare('INSERT OR IGNORE INTO AttributeGroupMap(GroupID, AttributeID) VALUES(?,?)');
		$insertProductAttribute = $db->prepare('INSERT OR IGNORE INTO ProductAttributeValue(ProductExternalReference, AttributeID, Value) VALUES (?, ?, ?)');
		$clearProductQuestion = $db->prepare('DELETE FROM ProductQuestionAnswer WHERE ProductQuestionExternalReference IN (SELECT ExternalReference FROM ProductQuestion WHERE ProductExternalReference = ?1); DELETE FROM ProductQuestion WHERE ProductExternalReference = ?1');
		$insertProductQuestion = $db->prepare('INSERT OR REPLACE INTO ProductQuestion(ExternalReference, ProductExternalReference, Name, Type, Sequence) VALUES (?, ?, ?, ?, ?)');
		$insertProductAnswer = $db->prepare('INSERT INTO ProductQuestionAnswer(ProductQuestionExternalReference, Value, PriceModifier, SKUModifier, Sequence) VALUES (?, ?, ?, ?, ?)');
		$insertOrders = $db->prepare('INSERT OR REPLACE INTO [Order] (ID, Status, PaymentDate, ShipmentDate, Carrier, TrackingNumber) VALUES (?, ?, ?, ?, ?, ?)');

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		$qry = $db->query('SELECT entity_id FROM Progress');

		$this->currentEntityId = $qry->fetchColumn();
		if(!$this->currentEntityId)
			$this->currentEntityId = 0;

		$qry->closeCursor();

		$qry = $db->query('SELECT State FROM Progress');

		$state = $qry->fetchColumn();

		$qry->closeCursor();

		$tablePrefix = Mage::getConfig()->getTablePrefix();

		if(!$state)
		{
			// Configuration
			$config = array(
				'baseurl' => Mage::getBaseUrl(),
				'skinurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN),
				'mediaurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA),
				'jsurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_JS),
				'storeurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB),
				'theme' => Mage::getDesign()->getTheme('frontend')
			);

			$imagepdf = Mage::getStoreConfig('sales/identity/logo', $store);
			$imagehtml = Mage::getStoreConfig('sales/identity/logo_html', $store);

			$path = null;
			if($imagepdf) {
				$path = Mage::getBaseDir('media') . '/sales/store/logo/' . $imagepdf;
			}
			if($imagehtml) {
				$path = Mage::getBaseDir('media') . '/sales/store/logo_html/' . $imagehtml;
			}

			if($path) {

				//Invoice and Packing Slip image location isn't accessible from frontend place into DB
				$data = file_get_contents($path);
				$base64 = base64_encode($data);

				$config['logobase64'] = $base64;
				$config['logourl'] = $path; //still stuff url in so we can get the MIME type to determine extra conversion on the other side

			}

			else {

				$package = Mage::getDesign()->getPackageName();
				$theme = Mage::getDesign()->getTheme('frontend');

				$config['logourl'] = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN) . 'frontend/' .
					$package . '/' . $theme . '/' . Mage::getStoreConfig('design/header/logo_src', $store);

			}

			$insertConfiguration = $db->prepare('INSERT INTO Configuration(configuration_key, configuration_value) VALUES(?,?)');

			// build configuration table
			foreach ($config as $key => $value) {
				$insertConfiguration->execute(array($key, $value));
			}

			$insertConfiguration->execute(array('currency', $store->getBaseCurrencyCode()));
			$insertConfiguration->execute(array('defaultcountry', Mage::getStoreConfig('tax/defaults/country', $store)));

			$state = 'simple';
		}

		$this->productsProcessed = array();
		$this->ordersProcessed = array();

		$coreResource = Mage::getSingleton('core/resource');

		$catalogWebsiteName = $coreResource->getTableName('catalog/product_website');
		$storeName = $coreResource->getTableName('core/store');

		if($state == 'simple')
		{
			// Simple Products not participating as configurable skus
			$simpleProducts = $this->getProductCollection()
								->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'special_from_date', 'special_to_date', 'status', 'tax_class_id', 'weight'), 'left')
								->addAttributeToFilter('type_id', array('eq' => 'simple'))
								->addAttributeToFilter('entity_id', array('gt' => (int)$this->currentEntityId));

			$simpleProducts->getSelect()
								->columns(array('codisto_in_store' => new Zend_Db_Expr('CASE WHEN `e`.entity_id IN (SELECT product_id FROM `'.$catalogWebsiteName.'` WHERE website_id IN (SELECT website_id FROM `'.$storeName.'` WHERE store_id = '.$storeId.' OR EXISTS(SELECT 1 FROM `'.$storeName.'` WHERE store_id = '.$storeId.' AND website_id = 0))) THEN -1 ELSE 0 END')))
								->order('entity_id')
								->limit($simpleCount);
			$simpleProducts->setOrder('entity_id', 'ASC');

			Mage::getSingleton('core/resource_iterator')->walk($simpleProducts->getSelect(), array(array($this, 'SyncSimpleProductData')),
				array(
					'type' => 'simple',
					'db' => $db,
					'preparedStatement' => $insertProduct,
					'preparedcheckproductStatement' => $checkProduct,
					'preparedcategoryproductStatement' => $insertCategoryProduct,
					'preparedimageStatement' => $insertImage,
					'preparedproducthtmlStatement' => $insertProductHTML,
					'preparedclearattributeStatement' => $clearAttribute,
					'preparedattributeStatement' => $insertAttribute,
					'preparedattributegroupStatement' => $insertAttributeGroup,
					'preparedattributegroupmapStatement' => $insertAttributeGroupMap,
					'preparedproductattributeStatement' => $insertProductAttribute,
					'preparedclearproductquestionStatement' => $clearProductQuestion,
					'preparedproductquestionStatement' => $insertProductQuestion,
					'preparedproductanswerStatement' => $insertProductAnswer,
					'store' => $store ));

			if(!empty($this->productsProcessed))
			{
				$db->exec('INSERT OR REPLACE INTO Progress (Sentinel, State, entity_id) VALUES (1, \'simple\', '.$this->currentEntityId.')');
			}
			else
			{
				$state = 'configurable';
				$this->currentEntityId = 0;
			}
		}

		if($state == 'configurable')
		{
			// Configurable products
			$configurableProducts = $this->getProductCollection()
								->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'special_from_date', 'special_to_date', 'status', 'tax_class_id', 'weight'), 'left')
								->addAttributeToFilter('type_id', array('eq' => 'configurable'))
								->addAttributeToFilter('entity_id', array('gt' => (int)$this->currentEntityId));

			$configurableProducts->getSelect()
										->columns(array('codisto_in_store' => new Zend_Db_Expr('CASE WHEN `e`.entity_id IN (SELECT product_id FROM `'.$catalogWebsiteName.'` WHERE website_id IN (SELECT website_id FROM `'.$storeName.'` WHERE store_id = '.$storeId.' OR EXISTS(SELECT 1 FROM `'.$storeName.'` WHERE store_id = '.$storeId.' AND website_id = 0))) THEN -1 ELSE 0 END')))
										->order('entity_id')
										->limit($configurableCount);
			$configurableProducts->setOrder('entity_id', 'ASC');

			Mage::getSingleton('core/resource_iterator')->walk($configurableProducts->getSelect(), array(array($this, 'SyncConfigurableProductData')),
				array(
					'type' => 'configurable',
					'db' => $db,
					'preparedStatement' => $insertProduct,
					'preparedcheckproductStatement' => $checkProduct,
					'preparedskuStatement' => $insertSKU,
					'preparedskulinkStatement' => $insertSKULink,
					'preparedskumatrixStatement' => $insertSKUMatrix,
					'preparedcategoryproductStatement' => $insertCategoryProduct,
					'preparedimageStatement' => $insertImage,
					'preparedproducthtmlStatement' => $insertProductHTML,
					'preparedclearattributeStatement' => $clearAttribute,
					'preparedattributeStatement' => $insertAttribute,
					'preparedattributegroupStatement' => $insertAttributeGroup,
					'preparedattributegroupmapStatement' => $insertAttributeGroupMap,
					'preparedproductattributeStatement' => $insertProductAttribute,
					'preparedclearproductquestionStatement' => $clearProductQuestion,
					'preparedproductquestionStatement' => $insertProductQuestion,
					'preparedproductanswerStatement' => $insertProductAnswer,
					'store' => $store )
			);

			if(!empty($this->productsProcessed))
			{
				$db->exec('INSERT OR REPLACE INTO Progress (Sentinel, State, entity_id) VALUES (1, \'configurable\', '.$this->currentEntityId.')');
			}
			else
			{
				$state = 'grouped';
				$this->currentEntityId = 0;
			}
		}

		if($state == 'grouped')
		{
			// Grouped products
			$groupedProducts = $this->getProductCollection()
								->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'special_from_date', 'special_to_date', 'status', 'tax_class_id', 'weight'), 'left')
								->addAttributeToFilter('type_id', array('eq' => 'grouped'))
								->addAttributeToFilter('entity_id', array('gt' => (int)$this->currentEntityId));

			$groupedProducts->getSelect()
										->columns(array('codisto_in_store' => new Zend_Db_Expr('CASE WHEN `e`.entity_id IN (SELECT product_id FROM `'.$catalogWebsiteName.'` WHERE website_id IN (SELECT website_id FROM `'.$storeName.'` WHERE store_id = '.$storeId.' OR EXISTS(SELECT 1 FROM `'.$storeName.'` WHERE store_id = '.$storeId.' AND website_id = 0))) THEN -1 ELSE 0 END')))
										->order('entity_id')
										->limit($configurableCount);
			$groupedProducts->setOrder('entity_id', 'ASC');

			Mage::getSingleton('core/resource_iterator')->walk($groupedProducts->getSelect(), array(array($this, 'SyncGroupedProductData')),
				array(
					'type' => 'grouped',
					'db' => $db,
					'preparedStatement' => $insertProduct,
					'preparedcheckproductStatement' => $checkProduct,
					'preparedskuStatement' => $insertSKU,
					'preparedskulinkStatement' => $insertSKULink,
					'preparedskumatrixStatement' => $insertSKUMatrix,
					'preparedcategoryproductStatement' => $insertCategoryProduct,
					'preparedimageStatement' => $insertImage,
					'preparedproducthtmlStatement' => $insertProductHTML,
					'preparedclearattributeStatement' => $clearAttribute,
					'preparedattributeStatement' => $insertAttribute,
					'preparedattributegroupStatement' => $insertAttributeGroup,
					'preparedattributegroupmapStatement' => $insertAttributeGroupMap,
					'preparedproductattributeStatement' => $insertProductAttribute,
					'preparedclearproductquestionStatement' => $clearProductQuestion,
					'preparedproductquestionStatement' => $insertProductQuestion,
					'preparedproductanswerStatement' => $insertProductAnswer,
					'store' => $store )
			);

			if(!empty($this->productsProcessed))
			{
				$db->exec('INSERT OR REPLACE INTO Progress (Sentinel, State, entity_id) VALUES (1, \'grouped\', '.$this->currentEntityId.')');
			}
			else
			{
				$state = 'orders';
				$this->currentEntityId = 0;
			}
		}

		if($state == 'orders')
		{
			if($this->currentEntityId == 0)
			{
				$connection = $coreResource->getConnection(Mage_Core_Model_Resource::DEFAULT_WRITE_RESOURCE);
				try
				{
					$connection->addColumn(
							$tablePrefix . 'sales_flat_order',
							'codisto_orderid',
							'varchar(10)'
						);
				}
				catch(Exception $e)
				{
				}
			}

			$orderStoreId = $storeId;
			if($storeId == 0)
			{
				$stores = Mage::getModel('core/store')->getCollection()
							->addFieldToFilter('is_active', array('neq' => 0))
							->addFieldToFilter('store_id', array( 'gt' => 0))
							->setOrder('store_id', 'ASC');
				$stores->setPageSize(1)->setCurPage(1);
				$orderStoreId = $stores->getFirstItem()->getId();
			}

			$invoiceName = $coreResource->getTableName('sales/invoice');
			$shipmentName = $coreResource->getTableName('sales/shipment');
			$shipmentTrackName = $coreResource->getTableName('sales/shipment_track');

			$ts = Mage::getModel('core/date')->gmtTimestamp();
			$ts -= 7776000; // 90 days

			$orders = Mage::getModel('sales/order')->getCollection()
						->addFieldToSelect(array('codisto_orderid', 'status'))
						->addAttributeToFilter('entity_id', array('gt' => (int)$this->currentEntityId ))
						->addAttributeToFilter('main_table.store_id', array('eq' => $orderStoreId ))
						->addAttributeToFilter('main_table.updated_at', array('gteq' => date('Y-m-d H:i:s', $ts)))
						->addAttributeToFilter('main_table.codisto_orderid', array('notnull' => true));
			$orders->getSelect()->joinLeft( array('i' => $invoiceName), 'i.order_id = main_table.entity_id AND i.state = 2', array('pay_date' => 'MIN(i.created_at)'));
			$orders->getSelect()->joinLeft( array('s' => $shipmentName), 's.order_id = main_table.entity_id', array('ship_date' => 'MIN(s.created_at)'));
			$orders->getSelect()->joinLeft( array('t' => $shipmentTrackName), 't.order_id = main_table.entity_id', array('carrier' => 'GROUP_CONCAT(COALESCE(t.title, \'\') SEPARATOR \',\')', 'track_number' => 'GROUP_CONCAT(COALESCE(t.track_number, \'\') SEPARATOR \',\')'));
			$orders->getSelect()->group(array('main_table.entity_id', 'main_table.codisto_orderid', 'main_table.status'));
			$orders->getSelect()->limit(1000);
			$orders->setOrder('entity_id', 'ASC');

			Mage::getSingleton('core/resource_iterator')->walk($orders->getSelect(), array(array($this, 'SyncOrderData')),
				array(
					'db' => $db,
					'preparedStatement' => $insertOrders,
					'store' => $store )
			);

			if(!empty($this->ordersProcessed))
			{
				$db->exec('INSERT OR REPLACE INTO Progress (Sentinel, State, entity_id) VALUES (1, \'orders\', '.$this->currentEntityId.')');
			}
			else
			{
				$state = 'productoption';
				$this->currentEntityId = 0;
			}
		}

		if($state == 'productoption')
		{
			$db->exec('DELETE FROM ProductOptionValue');

			$insertProductOptionValue = $db->prepare('INSERT INTO ProductOptionValue (ExternalReference, Sequence) VALUES (?,?)');

			$options = Mage::getResourceModel('eav/entity_attribute_option_collection')
						->setPositionOrder('asc', true)
						->load();

			$insertProductOptionValue->execute(array(0, 0));

			foreach($options as $opt){
				$sequence = $opt->getSortOrder();
				$optId = $opt->getId();
				$insertProductOptionValue->execute(array($optId, $sequence));
			}

			$state = 'categories';
		}

		if($state == 'categories')
		{
			// Categories
			$categories = Mage::getModel('catalog/category', array('disable_flat' => true))->getCollection()
								->addAttributeToSelect(array('name', 'image', 'is_active', 'updated_at', 'parent_id', 'position'), 'left');

			Mage::getSingleton('core/resource_iterator')->walk($categories->getSelect(), array(array($this, 'SyncCategoryData')), array( 'db' => $db, 'preparedStatement' => $insertCategory, 'store' => $store ));

			$db->exec('INSERT OR REPLACE INTO Progress (Sentinel, State, entity_id) VALUES (1, \'complete\', 0)');
		}

		if((empty($this->productsProcessed) && empty($this->ordersProcessed)) || $first)
		{
			$uniqueId = uniqid();

			$adapter = $coreResource->getConnection(Mage_Core_Model_Resource::DEFAULT_WRITE_RESOURCE);

			$adapter->beginTransaction();
			try
			{
				$adapter->query('REPLACE INTO `'.$tablePrefix.'codisto_sync` ( store_id, token ) VALUES ('.$storeId.', \''.$uniqueId.'\')');
			}
			catch(Exception $e)
			{
				$adapter->query('CREATE TABLE `'.$tablePrefix.'codisto_sync` (store_id smallint(5) unsigned PRIMARY KEY NOT NULL, token varchar(20) NOT NULL)');
				$adapter->insert($tablePrefix.'codisto_sync', array( 'token' => $uniqueId, 'store_id' => $storeId ));
			}
			$adapter->commit();

			$db->exec('CREATE TABLE IF NOT EXISTS Sync (token text NOT NULL, sentinel NOT NULL PRIMARY KEY DEFAULT 1, CHECK(sentinel = 1))');
			$db->exec('INSERT OR REPLACE INTO Sync (token) VALUES (\''.$uniqueId.'\')');
			$db->exec('COMMIT TRANSACTION');

			return 'complete';
		}
		else
		{
			$db->exec('COMMIT TRANSACTION');

			return 'pending';
		}
	}

	public function ProductTotals($storeId) {

		$store = Mage::app()->getStore($storeId);

		$configurableProducts = $this->getProductCollection()
							->addAttributeToSelect(array('entity_id'), 'left')
							->addAttributeToFilter('type_id', array('eq' => 'configurable'));

		$configurablecount = $configurableProducts->getSize();

		$simpleProducts = $this->getProductCollection()
							->addAttributeToSelect(array('entity_id'), 'left')
							->addAttributeToFilter('type_id', array('eq' => 'simple'));

		$simplecount = $simpleProducts->getSize();

		return array('simplecount' => $simplecount, 'configurablecount' => $configurablecount);
	}

	public function SyncStaticBlocks($syncDb, $storeId)
	{
		$db = $this->GetSyncDb($syncDb);

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		$db->exec('DELETE FROM StaticBlock');

		$insertStaticBlock = $db->prepare('INSERT OR IGNORE INTO StaticBlock (BlockID, Title, Identifier, Content) VALUES (?, ?, ?, ?)');

		$staticBlocks = Mage::getModel('cms/block')->getCollection()->addStoreFilter($storeId);

		foreach($staticBlocks as $block)
		{
			$BlockID = $block->getId();
			$Title = $block->getTitle();
			$Identifier = $block->getIdentifier();
			$Content = Mage::helper('codistosync')->processCmsContent($block->getContent());

			$insertStaticBlock->bindParam(1, $BlockID);
			$insertStaticBlock->bindParam(2, $Title);
			$insertStaticBlock->bindParam(3, $Identifier);
			$insertStaticBlock->bindParam(4, $Content);
			$insertStaticBlock->execute();
		}

		$db->exec('COMMIT TRANSACTION');
	}

	public function SyncTax($syncDb, $storeId)
	{
		$db = $this->GetSyncDb($syncDb);

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		$db->exec('DELETE FROM TaxClass');
		$db->exec('DELETE FROM TaxCalculation');
		$db->exec('DELETE FROM TaxCalculationRule');
		$db->exec('DELETE FROM TaxCalculationRate');

		$taxClasses = Mage::getModel('tax/class')->getCollection()
				->addFieldToSelect(array('class_id', 'class_type', 'class_name'))
				->addFieldToFilter('class_type', array('eq' => 'PRODUCT'));

		$insertTaxClass = $db->prepare('INSERT OR IGNORE INTO TaxClass (ID, Type, Name) VALUES (?, ?, ?)');

		foreach($taxClasses as $taxClass)
		{
			$TaxID = $taxClass->getId();
			$TaxName = $taxClass->getClassName();
			$TaxType = $taxClass->getClassType();

			$insertTaxClass->bindParam(1, $TaxID);
			$insertTaxClass->bindParam(2, $TaxType);
			$insertTaxClass->bindParam(3, $TaxName);
			$insertTaxClass->execute();
		}

		$ebayGroup = Mage::getModel('customer/group');
		$ebayGroup->load('eBay', 'customer_group_code');
		if(!$ebayGroup->getId())
			$ebayGroup->load(1);

		$customerTaxClassId = $ebayGroup->getTaxClassId();

		$taxCalcs = Mage::getModel('tax/calculation')->getCollection();
		if($customerTaxClassId)
			$taxCalcs->addFieldToFilter('customer_tax_class_id', array('eq' => $customerTaxClassId));

		$insertTaxCalc = $db->prepare('INSERT OR IGNORE INTO TaxCalculation (ID, TaxRateID, TaxRuleID, ProductTaxClassID, CustomerTaxClassID) VALUES (?, ?, ?, ?, ?)');

		$TaxRuleIDs = array();

		foreach($taxCalcs as $taxCalc)
		{
			$TaxCalcID = $taxCalc->getId();
			$TaxRateID = $taxCalc->getTaxCalculationRateId();
			$TaxRuleID = $taxCalc->getTaxCalculationRuleId();
			$ProductClass = $taxCalc->getProductTaxClassId();
			$CustomerClass = $taxCalc->getCustomerTaxClassId();

			$insertTaxCalc->bindParam(1, $TaxCalcID);
			$insertTaxCalc->bindParam(2, $TaxRateID);
			$insertTaxCalc->bindParam(3, $TaxRuleID);
			$insertTaxCalc->bindParam(4, $ProductClass);
			$insertTaxCalc->bindParam(5, $CustomerClass);
			$insertTaxCalc->execute();

			$TaxRuleIDs[] = $TaxRuleID;
		}

		$taxRules = Mage::getModel('tax/calculation_rule')->getCollection()
				->addFieldToFilter('tax_calculation_rule_id', array( 'in' => $TaxRuleIDs ));

		$insertTaxRule = $db->prepare('INSERT OR IGNORE INTO TaxCalculationRule (ID, Code, Priority, Position, CalculateSubTotal) VALUES (?, ?, ?, ?, ?)');

		foreach($taxRules as $taxRule)
		{
			$TaxRuleID = $taxRule->getId();
			$TaxRuleCode = $taxRule->getCode();
			$TaxRulePriority = $taxRule->getPriority();
			$TaxRulePosition = $taxRule->getPosition();
			$TaxRuleCalcSubTotal = $taxRule->getCalculateSubtotal();

			$insertTaxRule->bindParam(1, $TaxRuleID);
			$insertTaxRule->bindParam(2, $TaxRuleCode);
			$insertTaxRule->bindParam(3, $TaxRulePriority);
			$insertTaxRule->bindParam(4, $TaxRulePosition);
			$insertTaxRule->bindParam(5, $TaxRuleCalcSubTotal);
			$insertTaxRule->execute();
		}

		$regionName = Mage::getSingleton('core/resource')->getTableName('directory/country_region');

		$taxRates = Mage::getModel('tax/calculation_rate')->getCollection();
		$taxRates->getSelect()->joinLeft( array('region' => $regionName), 'region.region_id = main_table.tax_region_id', array('tax_region_code' => 'region.code', 'tax_region_name' => 'region.default_name'));

		$insertTaxRate = $db->prepare('INSERT OR IGNORE INTO TaxCalculationRate (ID, Country, RegionID, RegionName, RegionCode, PostCode, Code, Rate, IsRange, ZipFrom, ZipTo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ? ,?)');

		foreach($taxRates as $taxRate)
		{
			$TaxRateID = $taxRate->getId();
			$TaxCountry = $taxRate->getTaxCountryId();
			$TaxRegionID = $taxRate->getTaxRegionId();
			$TaxRegionName = $taxRate->getTaxRegionName();
			$TaxRegionCode = $taxRate->getTaxRegionCode();
			$TaxPostCode = $taxRate->getTaxPostcode();
			$TaxCode = $taxRate->getCode();
			$TaxRate = $taxRate->getRate();
			$TaxZipIsRange = $taxRate->getZipIsRange();
			$TaxZipFrom = $taxRate->getZipFrom();
			$TaxZipTo = $taxRate->getZipTo();

			$insertTaxRate->execute(array(
				$TaxRateID,
				$TaxCountry,
				$TaxRegionID,
				$TaxRegionName,
				$TaxRegionCode,
				$TaxPostCode,
				$TaxCode,
				$TaxRate,
				$TaxZipIsRange,
				$TaxZipFrom,
				$TaxZipTo
			));
		}

		$db->exec('COMMIT TRANSACTION');
	}

	public function SyncStores($syncDb, $storeId)
	{
		$db = $this->GetSyncDb($syncDb);

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');
		$db->exec('DELETE FROM Store');
		$db->exec('DELETE FROM StoreMerchant');

		$stores = Mage::getModel('core/store')->getCollection();

		$insertStore = $db->prepare('INSERT OR REPLACE INTO Store (ID, Code, Name, Currency) VALUES (?, ?, ?, ?)');
		$insertStoreMerchant = $db->prepare('INSERT OR REPLACE INTO StoreMerchant (StoreID, MerchantID) VALUES (?, ?)');

		$StoreID = 0;
		$StoreCode = 'admin';
		$StoreName = '';
		$StoreCurrency = Mage::app()->getStore($StoreID)->getCurrentCurrencyCode();

		$insertStore->execute(array($StoreID, $StoreCode, $StoreName, $StoreCurrency));

		$defaultMerchantList = Mage::getStoreConfig('codisto/merchantid', 0);
		if($defaultMerchantList)
		{
			$merchantlist = Zend_Json::decode($defaultMerchantList);
			if(!is_array($merchantlist))
				$merchantlist = array($merchantlist);

			foreach($merchantlist as $MerchantID)
			{
				$insertStoreMerchant->execute(array($StoreID, $MerchantID));
			}
		}

		foreach($stores as $store)
		{
			$StoreID = $store->getId();

			if($StoreID == 0)
				continue;

			$StoreCode = $store->getCode();
			$StoreName = $store->getName();
			$StoreCurrency = $store->getCurrentCurrencyCode();

			$insertStore->execute(array($StoreID, $StoreCode, $StoreName, $StoreCurrency));

			$storeMerchantList = $store->getConfig('codisto/merchantid');
			if($storeMerchantList && $storeMerchantList != $defaultMerchantList)
			{
				$merchantlist = Zend_Json::decode($storeMerchantList);
				if(!is_array($merchantlist))
					$merchantlist = array($merchantlist);

				foreach($merchantlist as $MerchantID)
				{
					$insertStoreMerchant->execute(array($StoreID, $MerchantID));
				}
			}
		}

		$db->exec('COMMIT TRANSACTION');
	}

	public function SyncOrders($syncDb, $orders, $storeId)
	{
		$store = Mage::app()->getStore($storeId);

		$db = $this->GetSyncDb($syncDb);

		$insertOrders = $db->prepare('INSERT OR REPLACE INTO [Order] (ID, Status, PaymentDate, ShipmentDate, Carrier, TrackingNumber) VALUES (?, ?, ?, ?, ?, ?)');

		$coreResource = Mage::getSingleton('core/resource');

		$invoiceName = $coreResource->getTableName('sales/invoice');
		$shipmentName = $coreResource->getTableName('sales/shipment');
		$shipmentTrackName = $coreResource->getTableName('sales/shipment_track');

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		$orders = Mage::getModel('sales/order')->getCollection()
					->addFieldToSelect(array('codisto_orderid', 'status'))
					->addAttributeToFilter('codisto_orderid', array('in' => $orders ));

		$orders->getSelect()->joinLeft( array('i' => $invoiceName), 'i.order_id = main_table.entity_id AND i.state = 2', array('pay_date' => 'MIN(i.created_at)'));
		$orders->getSelect()->joinLeft( array('s' => $shipmentName), 's.order_id = main_table.entity_id', array('ship_date' => 'MIN(s.created_at)'));
		$orders->getSelect()->joinLeft( array('t' => $shipmentTrackName), 't.order_id = main_table.entity_id', array('carrier' => 'GROUP_CONCAT(COALESCE(t.title, \'\') SEPARATOR \',\')', 'track_number' => 'GROUP_CONCAT(COALESCE(t.track_number, \'\') SEPARATOR \',\')'));
		$orders->getSelect()->group(array('main_table.entity_id', 'main_table.codisto_orderid', 'main_table.status'));

		$orders->setOrder('entity_id', 'ASC');

		Mage::getSingleton('core/resource_iterator')->walk($orders->getSelect(), array(array($this, 'SyncOrderData')), array( 'db' => $db, 'preparedStatement' => $insertOrders, 'store' => $store ));

		$db->exec('COMMIT TRANSACTION');
	}

	private function GetSyncDb($syncDb)
	{
		$db = new PDO('sqlite:' . $syncDb);

		Mage::helper('codistosync')->prepareSqliteDatabase($db);

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');
		$db->exec('CREATE TABLE IF NOT EXISTS Progress(entity_id integer NOT NULL, State text NOT NULL, Sentinel integer NOT NULL PRIMARY KEY AUTOINCREMENT, CHECK(Sentinel=1))');
		$db->exec('CREATE TABLE IF NOT EXISTS Category(ExternalReference text NOT NULL PRIMARY KEY, ParentExternalReference text NOT NULL, '.
							'Name text NOT NULL, LastModified datetime NOT NULL, Enabled bit NOT NULL, Sequence integer NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS CategoryProduct (CategoryExternalReference text NOT NULL, ProductExternalReference text NOT NULL, Sequence integer NOT NULL, PRIMARY KEY(CategoryExternalReference, ProductExternalReference))');
		$db->exec('CREATE TABLE IF NOT EXISTS Product (ExternalReference text NOT NULL PRIMARY KEY, Type text NOT NULL, Code text NULL, Name text NOT NULL, Price real NOT NULL, ListPrice real NOT NULL, TaxClass text NOT NULL, Description text NOT NULL, '.
					'Enabled bit NOT NULL,  '.
					'StockControl bit NOT NULL, StockLevel integer NOT NULL, '.
					'Weight real NULL, '.
					'InStore bit NOT NULL)');

		$db->exec('CREATE TABLE IF NOT EXISTS ProductOptionValue (ExternalReference text NOT NULL, Sequence integer NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductOptionValue_ExternalReference ON ProductOptionValue(ExternalReference)');

		$db->exec('CREATE TABLE IF NOT EXISTS ProductQuestion (ExternalReference text NOT NULL PRIMARY KEY, ProductExternalReference text NOT NULL, Name text NOT NULL, Type text NOT NULL, Sequence integer NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductQuestion_ProductExternalReference ON ProductQuestion(ProductExternalReference)');
		$db->exec('CREATE TABLE IF NOT EXISTS ProductQuestionAnswer (ProductQuestionExternalReference text NOT NULL, Value text NOT NULL, PriceModifier text NOT NULL, SKUModifier text NOT NULL, Sequence integer NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductQuestionAnswer_ProductQuestionExternalReference ON ProductQuestionAnswer(ProductQuestionExternalReference)');

		$db->exec('CREATE TABLE IF NOT EXISTS SKU (ExternalReference text NOT NULL PRIMARY KEY, Code text NULL, ProductExternalReference text NOT NULL, Name text NOT NULL, StockControl bit NOT NULL, StockLevel integer NOT NULL, Price real NOT NULL, Enabled bit NOT NULL, InStore bit NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_SKU_ProductExternalReference ON SKU(ProductExternalReference)');
		$db->exec('CREATE TABLE IF NOT EXISTS SKUMatrix (SKUExternalReference text NOT NULL, ProductExternalReference text NOT NULL, Code text NULL, OptionName text NOT NULL, OptionValue text NOT NULL, ProductOptionExternalReference text NOT NULL, ProductOptionValueExternalReference text NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_SKUMatrix_SKUExternalReference ON SKUMatrix(SKUExternalReference)');

		$db->exec('CREATE TABLE IF NOT EXISTS SKULink (SKUExternalReference text NOT NULL, ProductExternalReference text NOT NULL, Price real NOT NULL, PRIMARY KEY (SKUExternalReference, ProductExternalReference))');

		$db->exec('CREATE TABLE IF NOT EXISTS ProductImage (ProductExternalReference text NOT NULL, URL text NOT NULL, Tag text NOT NULL DEFAULT \'\', Sequence integer NOT NULL, Enabled bit NOT NULL DEFAULT -1)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductImage_ProductExternalReference ON ProductImage(ProductExternalReference)');

		$db->exec('CREATE TABLE IF NOT EXISTS ProductHTML (ProductExternalReference text NOT NULL, Tag text NOT NULL, HTML text NOT NULL, PRIMARY KEY (ProductExternalReference, Tag))');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductHTML_ProductExternalReference ON ProductHTML(ProductExternalReference)');

		$db->exec('CREATE TABLE IF NOT EXISTS Attribute (ID integer NOT NULL PRIMARY KEY, Code text NOT NULL, Label text NOT NULL, Type text NOT NULL, Input text NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS AttributeGroupMap (AttributeID integer NOT NULL, GroupID integer NOT NULL, PRIMARY KEY(AttributeID, GroupID))');
		$db->exec('CREATE TABLE IF NOT EXISTS AttributeGroup (ID integer NOT NULL PRIMARY KEY, Name text NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS ProductAttributeValue (ProductExternalReference text NOT NULL, AttributeID integer NOT NULL, Value any, PRIMARY KEY (ProductExternalReference, AttributeID))');

		$db->exec('CREATE TABLE IF NOT EXISTS TaxClass (ID integer NOT NULL PRIMARY KEY, Type text NOT NULL, Name text NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS TaxCalculation(ID integer NOT NULL PRIMARY KEY, TaxRateID integer NOT NULL, TaxRuleID integer NOT NULL, ProductTaxClassID integer NOT NULL, CustomerTaxClassID integer NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS TaxCalculationRule(ID integer NOT NULL PRIMARY KEY, Code text NOT NULL, Priority integer NOT NULL, Position integer NOT NULL, CalculateSubTotal bit NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS TaxCalculationRate(ID integer NOT NULL PRIMARY KEY, Country text NOT NULL, RegionID integer NOT NULL, RegionName text NULL, RegionCode text NULL, PostCode text NOT NULL, Code text NOT NULL, Rate real NOT NULL, IsRange bit NULL, ZipFrom text NULL, ZipTo text NULL)');


		$db->exec('CREATE TABLE IF NOT EXISTS Store(ID integer NOT NULL PRIMARY KEY, Code text NOT NULL, Name text NOT NULL, Currency text NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS StoreMerchant(StoreID integer NOT NULL, MerchantID integer NOT NULL, PRIMARY KEY (StoreID, MerchantID))');

		$db->exec('CREATE TABLE IF NOT EXISTS [Order](ID integer NOT NULL PRIMARY KEY, Status text NOT NULL, PaymentDate datetime NULL, ShipmentDate datetime NULL, Carrier text NOT NULL, TrackingNumber text NOT NULL)');

		$db->exec('CREATE TABLE IF NOT EXISTS StaticBlock(BlockID integer NOT NULL PRIMARY KEY, Title text NOT NULL, Identifier text NOT NULL, Content text NOT NULL)');

		$db->exec('CREATE TABLE IF NOT EXISTS Configuration (configuration_id integer, configuration_title text, configuration_key text, configuration_value text, configuration_description text, configuration_group_id integer, sort_order integer, last_modified datetime, date_added datetime, use_function text, set_function text)');

		try
		{
			$db->exec('SELECT 1 FROM [Order] WHERE Carrier IS NULL LIMIT 1');
		}
		catch(Exception $e)
		{
			$db->exec('CREATE TABLE NewOrder (ID integer NOT NULL PRIMARY KEY, Status text NOT NULL, PaymentDate datetime NULL, ShipmentDate datetime NULL, Carrier text NOT NULL, TrackingNumber text NOT NULL)');
			$db->exec('INSERT INTO NewOrder SELECT ID, Status, PaymentDate, ShipmentDate, \'Unknown\', TrackingNumber FROM [Order]');
			$db->exec('DROP TABLE [Order]');
			$db->exec('ALTER TABLE NewOrder RENAME TO [Order]');
		}

		try
		{
			$db->exec('SELECT 1 FROM ProductAttributeValue WHERE ProductExternalReference IS NULL LIMIT 1');
		}
		catch(Exception $e)
		{
			$db->exec('CREATE TABLE NewProductAttributeValue (ProductExternalReference integer NOT NULL, AttributeID integer NOT NULL, Value any, PRIMARY KEY (ProductExternalReference, AttributeID))');
			$db->exec('INSERT INTO NewProductAttributeValue SELECT ProductID, AttributeID, Value FROM ProductAttributeValue');
			$db->exec('DROP TABLE ProductAttributeValue');
			$db->exec('ALTER TABLE NewProductAttributeValue RENAME TO ProductAttributeValue');
		}

		try
		{
			$db->exec('SELECT 1 FROM Store WHERE MerchantID IS NULL LIMIT 1');

			$db->exec('CREATE TABLE NewStore(ID integer NOT NULL PRIMARY KEY, Code text NOT NULL, Name text NOT NULL)');
			$db->exec('INSERT INTO NewStore SELECT ID, Code, Name FROM Store');
			$db->exec('DROP TABLE Store');
			$db->exec('ALTER TABLE NewStore RENAME TO Store');
		}
		catch(Exception $e)
		{

		}

		try
		{
			$db->exec('SELECT 1 FROM ProductImage WHERE Enabled IS NULL LIMIT 1');
		}
		catch(Exception $e)
		{
			try
			{
				$db->exec('ALTER TABLE ProductImage ADD COLUMN Enabled bit NOT NULL DEFAULT -1');
			}
			catch(Exception $e2)
			{
			}
		}

		try
		{
			$db->exec('SELECT ProductExternalReference FROM ProductOptionValue LIMIT 1');

			$db->exec('CREATE TABLE IF NOT EXISTS TmpProductOptionValue (ExternalReference text NOT NULL, Sequence integer NOT NULL)');
			$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductOptionValue_ExternalReference_Sequence ON ProductOptionValue(ExternalReference, Sequence)');
			$db->exec('INSERT INTO TmpProductOptionValue (ExternalReference, Sequence) SELECT DISTINCT ExternalReference, Sequence FROM ProductOptionValue');
			$db->exec('DROP TABLE ProductOptionValue');
			$db->exec('ALTER TABLE TmpProductOptionValue RENAME TO ProductOptionValue');
			$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductOptionValue_ExternalReference ON ProductOptionValue(ExternalReference)');
		}
		catch (Exception $e)
		{

		}

		try
		{
			$db->exec('SELECT ProductExternalReference FROM SKUMatrix LIMIT 1');

			$db->exec('ALTER TABLE SKUMatrix ADD COLUMN ProductExternalReference text NOT NULL DEFAULT \'\'');
			$db->exec('UPDATE SKUMatrix SET ProductExternalReference = (SELECT ProductExternalReference FROM SKU WHERE SKUExternalReference = SKUMatrix.SKUExternalReference)');
		}
		catch (Exception $e)
		{

		}

		try
		{
			$db->exec('SELECT InStore FROM Product LIMIT 1');
		}
		catch(Exception $e)
		{
			try
			{
				$db->exec('ALTER TABLE Product ADD COLUMN InStore bit NOT NULL DEFAULT -1');
				$db->exec('ALTER TABLE SKU ADD COLUMN InStore bit NOT NULL DEFAULT -1');
			}
			catch(Exception $e2)
			{
			}
		}

		try
		{
			$db->exec('SELECT Type FROM Product LIMIT 1');
		}
		catch(Exception $e)
		{
			try
			{
				$db->exec('ALTER TABLE Product ADD COLUMN Type text NOT NULL DEFAULT \'s\'');
				$db->exec('UPDATE Product SET Type = \'c\' WHERE ExternalReference IN (SELECT ProductExternalReference FROM SKULink) OR ExternalReference IN (SELECT ProductExternalReference FROM SKU)');
			}
			catch(Exception $e2)
			{
			}
		}

		try
		{
			$db->exec('SELECT Input FROM Attribute LIMIT 1');
		}
		catch(Exception $e)
		{
			try
			{
				$db->exec('ALTER TABLE Attribute ADD COLUMN Input text NOT NULL DEFAULT \'text\'');
			}
			catch (Exception $e2)
			{
			}

		}

		$db->exec('COMMIT TRANSACTION');

		return $db;
	}

	private function GetTemplateDb($templateDb)
	{
		$db = new PDO('sqlite:' . $templateDb);

		Mage::helper('codistosync')->prepareSqliteDatabase($db);

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');
		$db->exec('CREATE TABLE IF NOT EXISTS File(Name text NOT NULL PRIMARY KEY, Content blob NOT NULL, LastModified datetime NOT NULL, Changed bit NOT NULL DEFAULT -1)');
		$db->exec('COMMIT TRANSACTION');

		return $db;
	}

	private function getProductCollection()
	{
		$process = Mage::helper('catalog/product_flat')->getProcess();
		$status = $process->getStatus();
		$process->setStatus(Mage_Index_Model_Process::STATUS_RUNNING);

		$collection = Mage::getResourceModel('catalog/product_collection');

		$process->setStatus($status);

		return $collection;
	}

	private function getExTaxPrice($product, $pricein, $store)
	{
		if($this->useTaxHelper)
		{
			$price = Mage::helper('tax')->getPrice($product, $pricein, false, null, null, null, $store, null, false);
		}
		else
		{
			if(!$this->rateRequest)
				$this->rateRequest = $this->taxCalculation->getRateRequest(null, null, null, $store);

			$taxClassId = $product->getTaxClassId();
			$percent = $this->taxCalculation->getRate($this->rateRequest->setProductClassId($taxClassId));

			$price = $pricein / (1.0+($percent/100.0));
		}

		return $price;
	}
}
