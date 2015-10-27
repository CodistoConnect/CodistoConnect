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

	private $cmsHelper;

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

		$this->cmsHelper = Mage::helper('cms');
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
			$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

			$db->exec('PRAGMA synchronous=0');
			$db->exec('PRAGMA temp_store=2');
			$db->exec('PRAGMA page_size=65536');
			$db->exec('PRAGMA encoding=\'UTF-8\'');
			$db->exec('PRAGMA cache_size=15000');
			$db->exec('PRAGMA soft_heap_limit=67108864');
			$db->exec('PRAGMA journal_mode=MEMORY');

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

		$category = Mage::getModel('catalog/category')->getCollection()
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
		$insertProduct = $db->prepare('INSERT INTO Product(ExternalReference, Code, Name, Price, ListPrice, TaxClass, Description, Enabled, StockControl, StockLevel, Weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
		$insertSKU = $db->prepare('INSERT OR IGNORE INTO SKU(ExternalReference, Code, ProductExternalReference, Name, StockControl, StockLevel, Price, Enabled) VALUES(?,?,?,?,?,?,?,?)');
		$insertSKUMatrix = $db->prepare('INSERT INTO SKUMatrix(SKUExternalReference, Code, OptionName, OptionValue, ProductOptionExternalReference, ProductOptionValueExternalReference) VALUES(?,?,?,?,?,?)');
		$insertImage = $db->prepare('INSERT INTO ProductImage(ProductExternalReference, URL, Tag, Sequence) VALUES(?,?,?,?)');
		$insertSKUImage = $db->prepare('INSERT INTO SKUImage(SKUExternalReference, URL, Tag, Sequence) VALUES(?,?,?,?)');
		$insertProductOption = $db->prepare('INSERT INTO ProductOption (ExternalReference, Sequence, ProductExternalReference) VALUES (?,?,?)');
		$insertProductOptionValue = $db->prepare('INSERT INTO ProductOptionValue (ExternalReference, Sequence, ProductOptionExternalReference, ProductExternalReference) VALUES (?,?,?,?)');
		$insertProductHTML = $db->prepare('INSERT OR IGNORE INTO ProductHTML(ProductExternalReference, Tag, HTML) VALUES (?, ?, ?)');
		$insertAttribute = $db->prepare('INSERT OR REPLACE INTO Attribute(ID, Code, Label, Type) VALUES (?, ?, ?, ?)');
		$insertProductAttribute = $db->prepare('INSERT OR IGNORE INTO ProductAttributeValue(ProductExternalReference, AttributeID, Value) VALUES (?, ?, ?)');

		$this->productsProcessed = array();

		$superLinkName = Mage::getSingleton('core/resource')->getTableName('catalog/product_super_link');

		// Configurable products
		$configurableProducts = Mage::getModel('catalog/product')->getCollection()
							->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'status', 'tax_class_id', 'weight'), 'left')
							->addAttributeToFilter('type_id', array('eq' => 'configurable'));

		$configurableProducts->getSelect()->where('`e`.entity_id IN ('.implode(',', $ids).') OR `e`.entity_id IN (SELECT parent_id FROM '.$superLinkName.' WHERE product_id IN ('.implode(',', $ids).'))');

		// Simple Products not participating as configurable skus
		$simpleProducts = Mage::getModel('catalog/product')->getCollection()
							->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'status', 'tax_class_id', 'weight'), 'left')
							->addAttributeToFilter('type_id', array('eq' => 'simple'))
							->addAttributeToFilter('entity_id', array('in' => $ids));

		$simpleProducts->getSelect()->where('`e`.entity_id NOT IN (SELECT product_id FROM '.$superLinkName.')');

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		$db->exec('DELETE FROM Product WHERE ExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM ProductImage WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM ProductOption WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM ProductOptionValue WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM ProductHTML WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM SKUMatrix WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference IN ('.implode(',', $ids).'))');
		$db->exec('DELETE FROM SKUImage WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference IN ('.implode(',', $ids).'))');
		$db->exec('DELETE FROM SKU WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM CategoryProduct WHERE ProductExternalReference IN ('.implode(',', $ids).')');

		Mage::getSingleton('core/resource_iterator')->walk($configurableProducts->getSelect(), array(array($this, 'SyncConfigurableProductData')), array( 'type' => 'configurable', 'db' => $db, 'preparedStatement' => $insertProduct, 'preparedskuStatement' => $insertSKU, 'preparedskumatrixStatement' => $insertSKUMatrix, 'preparedcategoryproductStatement' => $insertCategoryProduct, 'preparedimageStatement' => $insertImage, 'preparedskuimageStatement' => $insertSKUImage, 'preparedproductoptionStatement' => $insertProductOption,  'preparedproductoptionvalueStatement' => $insertProductOptionValue, 'preparedproducthtmlStatement' => $insertProductHTML, 'preparedattributeStatement' => $insertAttribute, 'preparedproductattributeStatement' => $insertProductAttribute, 'store' => $store ));

		$db->exec('DELETE FROM Product WHERE ExternalReference IN ('.implode(',', $ids).') AND ExternalReference NOT IN (SELECT ProductExternalReference FROM SKU WHERE ProductExternalReference IS NOT NULL)');

		Mage::getSingleton('core/resource_iterator')->walk($simpleProducts->getSelect(), array(array($this, 'SyncSimpleProductData')), array( 'type' => 'simple', 'db' => $db, 'preparedStatement' => $insertProduct, 'preparedcategoryproductStatement' => $insertCategoryProduct, 'preparedimageStatement' => $insertImage, 'preparedproducthtmlStatement' => $insertProductHTML, 'preparedattributeStatement' => $insertAttribute, 'preparedproductattributeStatement' => $insertProductAttribute, 'store' => $store ));

		$db->exec('COMMIT TRANSACTION');
	}

	public function DeleteProduct($syncDb, $id, $storeId)
	{
		$db = $this->GetSyncDb($syncDb);

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		$db->exec(	'CREATE TABLE IF NOT EXISTS ProductDelete(ExternalReference text NOT NULL PRIMARY KEY);'.
					'INSERT OR IGNORE INTO ProductDelete VALUES('.$id.');'.
					'DELETE FROM Product WHERE ExternalReference = '.$id.';'.
					'DELETE FROM ProductImage WHERE ProductExternalReference = '.$id.';'.
					'DELETE FROM ProductOption WHERE ProductExternalReference = '.$id.';'.
					'DELETE FROM ProductOptionValue WHERE ProductExternalReference = '.$id.';'.
					'DELETE FROM ProductHTML WHERE ProductExternalReference = '.$id.';'.
					'DELETE FROM SKUMatrix WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference = '.$id.');'.
					'DELETE FROM SKUImage WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference = '.$id.');'.
					'DELETE FROM SKU WHERE ProductExternalReference = '.$id.';'.
					'DELETE FROM CategoryProduct WHERE ProductExternalReference = '.$id);

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
			$data[] = $categoryData[$key];
		}

		$insertSQL->execute($data);
	}

	public function SyncSKUData($args)
	{
		$skuData = $args['row'];
		$db = $args['db'];

		if($db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM SKU WHERE ExternalReference = '.$skuData['entity_id'].') THEN 1 ELSE 0 END')->fetchColumn())
			return;

		$store = $args['store'];

		$attributes = $args['attributes'];
		$pricesByAttributeValues = $args['prices'];

		foreach ($attributes as $attribute) {

			$productAttribute = $attribute->getProductAttribute();

			$attributeOptionId = Mage::getResourceModel('catalog/product')->getAttributeRawValue($skuData['entity_id'], $productAttribute->getAttributeCode(), $store->getId());

			$skuData[$productAttribute->getAttributeCode()] = $attributeOptionId;
		}

		$product = Mage::getModel('catalog/product');
		$product->setData($skuData);

		$insertSQL = $args['preparedStatement'];
		$insertCategorySQL = $args['preparedcategoryproductStatement'];
		$insertImageSQL = $args['preparedimageStatement'];
		$insertSKUMatrixSQL = $args['preparedskumatrixStatement'];

		$stockData = Mage::getModel('cataloginventory/stock_item')->loadByProduct($skuData['entity_id'])->getData();

		$totalPrice = $args['baseprice'];

		foreach ($attributes as $attribute) {

			$productAttribute = $attribute->getProductAttribute();

			$attributeOptionId = $product->getData($productAttribute->getAttributeCode());

			//add the price adjustment to the total price of the simple product
			if (isset($pricesByAttributeValues[$attributeOptionId])) {
				$totalPrice += $pricesByAttributeValues[$attributeOptionId];
			}
		}

		$price = $this->getExTaxPrice($product, $totalPrice, $store);

		$skuName = $skuData['name'];
		if(!$skuName)
			$skuName = '';

		$data = array();
		$data[] = $skuData['entity_id'];
		$data[] = $skuData['sku'];
		$data[] = $args['parent_id'];
		$data[] = $skuName;
		$data[] = !isset($stockData['use_config_manage_stock']) || $stockData['use_config_manage_stock'] == 0 ?
					(isset($stockData['manage_stock']) && $stockData['manage_stock'] ? -1 : 0) :
					(Mage::getStoreConfig('cataloginventory/item_options/manage_stock', $store) ? -1 : 0);
		$data[] = isset($stockData['qty']) && is_numeric($stockData['qty']) ? (int)$stockData['qty'] : 1;
		$data[] = $price;
		$data[] = $skuData['status'] != 1 ? 0 : -1;

		$insertSQL->execute($data);

		$categoryIds = $product->getCategoryIds();
		foreach ($categoryIds as $categoryId) {
			$insertCategorySQL->execute(array($args['parent_id'], $categoryId, 0));
		}

		// SKU Matrix
		foreach($attributes as $attribute)
		{
			$productAttribute = $attribute->getProductAttribute();
			$productOptionId = $productAttribute->getId();
			$productOptionValueId = $product->getData($productAttribute->getAttributeCode());

			$attributeName = $productAttribute->getFrontendLabel();
			$attributeValue = $product->getAttributeText($productAttribute->getAttributeCode());

			$insertSKUMatrixSQL->execute(array($skuData['entity_id'], '', $attributeName, $attributeValue, $productOptionId, $productOptionValueId));
		}

		$hasImage = false;

		$product->load('media_gallery');
		foreach ($product->getMediaGallery('images') as $image) {

			$imgURL = $product->getMediaConfig()->getMediaUrl($image['file']);

			if($image['file'] == $skuData['image'])
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

			$insertImageSQL->execute(array($skuData['entity_id'], $imgURL, $tag, $sequence));

			$hasImage = true;
		}
	}

	public function SyncConfigurableProductData($args)
	{
		$productData = $args['row'];

		$store = $args['store'];
		$db = $args['db'];

		$insertSQL = $args['preparedskuStatement'];
		$insertCategorySQL = $args['preparedcategoryproductStatement'];
		$insertImageSQL = $args['preparedskuimageStatement'];
		$insertSKUMatrixSQL = $args['preparedskumatrixStatement'];
		$insertProductOptionSQL = $args['preparedproductoptionStatement'];
		$insertProductOptionValueSQL = $args['preparedproductoptionvalueStatement'];

		$productData['sku'] = null;

		$this->SyncSimpleProductData(array_merge($args, array('row' => $productData)));

		$product = Mage::getModel('catalog/product');
		$product->setData($productData);

		$configurableData = Mage::getModel('catalog/product_type_configurable');

		$configurableData->setProduct($product);

		$configurableAttributes = $configurableData->getConfigurableAttributes($product);


		// NOTE: we can't call $product->getFinalPrice below as it interacts with getUsedProductCollection in strange ways
		// so we create a canary product object just to calculate the price then throw it away
		$productCanary = Mage::getModel('catalog/product');
		$productCanary->setData($productData);

		$basePrice = $productCanary->getFinalPrice();

		unset($productCanary);

		$pricesByAttributeValues = array();
		$configurableProductOptionSequence = array();

		foreach ($configurableAttributes as $attribute){
			$prices = $attribute->getPrices();
			if(is_array($prices))
			{
				foreach ($prices as $price){

					if ($price['is_percent']){ //if the price is specified in percents
						$pricesByAttributeValues[$price['value_index']] = (float)$price['pricing_value'] * $basePrice / 100;
					}
					else { //if the price is absolute value
						$pricesByAttributeValues[$price['value_index']] = (float)$price['pricing_value'];
					}
				}
			}


			$attributeid = $attribute->getAttributeId();
			$position = $attribute->getPosition();
			$pid = $attribute->getProductId();

			$insertProductOptionSQL->execute(array($attributeid, $position, $pid));

			$options = Mage::getResourceModel('eav/entity_attribute_option_collection')
						->setAttributeFilter($attributeid)
						->setPositionOrder('asc', true)
						->load();

			foreach($options as $opt){
				$sequence = $opt->getSortOrder();
				$optId = $opt->getId();
				$insertProductOptionValueSQL->execute(array($optId, $sequence, $attributeid, $pid));
			}

		}

		$productOptionValueSequence = array();

		$childProducts = $configurableData->getUsedProductCollection()
							->addAttributeToSelect(array('name', 'image', 'status', 'price', 'special_price', 'tax_class_id'), 'left');

		Mage::getSingleton('core/resource_iterator')->walk($childProducts->getSelect(), array(array($this, 'SyncSKUData')), array( 'parent_id' => $productData['entity_id'], 'attributes' => $configurableAttributes, 'baseprice' => $basePrice, 'prices' => $pricesByAttributeValues, 'db' => $db, 'preparedStatement' => $insertSQL, 'preparedskumatrixStatement' => $insertSKUMatrixSQL, 'preparedcategoryproductStatement' => $insertCategorySQL, 'preparedimageStatement' => $insertImageSQL, 'store' => $store ));

		$this->productsProcessed[] = $productData['entity_id'];

		if($productData['entity_id'] > $this->currentEntityId)
			$this->currentEntityId = $productData['entity_id'];
	}

	public function SyncSimpleProductData($args)
	{
		$type = $args['type'];

		$db = $args['db'];

		$store = $args['store'];
		$productData = $args['row'];

		$product = Mage::getModel('catalog/product');
		$product->setData($productData);

		$stockData = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productData['entity_id'])->getData();

		$insertSQL = $args['preparedStatement'];
		$insertCategorySQL = $args['preparedcategoryproductStatement'];
		$insertImageSQL = $args['preparedimageStatement'];
		$insertHTMLSQL = $args['preparedproducthtmlStatement'];
		$insertAttributeSQL = $args['preparedattributeStatement'];
		$insertProductAttributeSQL = $args['preparedproductattributeStatement'];

		$price = $this->getExTaxPrice($product, $product->getFinalPrice(), $store);
		$listPrice = $this->getExTaxPrice($product, $product->getPrice(), $store);
		if($listPrice == null)
			$listPrice = $price;

		// work around for description not appearing via collection
		if(!isset($productData['description']))
		{
			$tmpProduct = Mage::getModel('catalog/product')->load($productData['entity_id']);
			$description = $tmpProduct->getDescription();
			unset($tmpProduct);
		}
		else
		{
			$description = $productData['description'];
		}

		$description = $this->cmsHelper->getBlockTemplateProcessor()->filter(preg_replace('/^\s+|\s+$/', '', $description));
		if($type == 'simple' &&
			$description == '')
		{
			$parentIds = Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild($productData['entity_id']);

			if($parentIds && is_object($parentIds))
			{
				$groupedParentId = $parentIds->getFirstItem();
				if($groupedParentId)
				{
					$groupedParent = Mage::getModel('catalog/product')->load($groupedParentId);
					if($groupedParent)
						$description = $this->cmsHelper->getBlockTemplateProcessor()->filter(preg_replace('/^\s+|\s+$/', '', $groupedParent->description));
				}
			}

			if(!$description)
				$description = '';
		}

		$productName = $productData['name'];
		if(!$productName)
			$productName = '';

		$data = array();

		$data[] = $productData['entity_id'];
		$data[] = $productData['sku'];
		$data[] = $productName;
		$data[] = $price;
		$data[] = $listPrice;
		$data[] = isset($productData['tax_class_id']) && $productData['tax_class_id'] ? $productData['tax_class_id'] : '';
		$data[] = $description;
		$data[] = $productData['status'] != 1 ? 0 : -1;
		$data[] = !isset($stockData['use_config_manage_stock']) || $stockData['use_config_manage_stock'] == 0 ?
					(isset($stockData['manage_stock']) && $stockData['manage_stock'] ? -1 : 0) :
					(Mage::getStoreConfig('cataloginventory/item_options/manage_stock', $store) ? -1 : 0);
		$data[] = isset($stockData['qty']) && is_numeric($stockData['qty']) ? (int)$stockData['qty'] : 1;
		$data[] = isset($productData['weight']) && is_numeric($productData['weight']) ? (float)$productData['weight'] : $productData['weight'];

		if($db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM Product WHERE ExternalReference = '.$productData['entity_id'].') THEN 1 ELSE 0 END')->fetchColumn())
			return;

		$insertSQL->execute($data);

		$categoryIds = $product->getCategoryIds();
		foreach ($categoryIds as $categoryId) {
			$insertCategorySQL->execute(array($productData['entity_id'], $categoryId, 0));
		}

		if(isset($productData['short_description']) && strlen($productData['short_description']) > 0)
		{
			$shortDescription = $this->cmsHelper->getBlockTemplateProcessor()->filter(preg_replace('/^\s+|\s+$/', '', $productData['short_description']));

			$insertHTMLSQL->execute(array($productData['entity_id'], 'Short Description', $shortDescription));
		}

		$db->exec('DELETE FROM ProductAttributeValue WHERE ProductExternalReference = ' . (int)$productData['entity_id']);

		$attributes = $product->getAttributes();
		foreach($attributes as $attribute)
		{
			$backendType = $attribute->getBackendType();
			if($backendType == 'text')
			{
				$TextValue = Mage::getResourceModel('catalog/product')->getAttributeRawValue($productData['entity_id'], $attribute->getAttributeCode(), $store->getId());

				$insertHTMLSQL->execute(array($productData['entity_id'], $attribute->getStoreLabel(), $TextValue));
			}
			else if($backendType != 'static')
			{
				$AttributeID = $attribute->getId();
				$AttributeLabel = $attribute->getStoreLabel();

				if($AttributeLabel)
				{
					$AttributeValue = Mage::getResourceModel('catalog/product')->getAttributeRawValue($productData['entity_id'], $attribute->getAttributeCode(), $store->getId());

					$insertAttributeSQL->execute(array($AttributeID, $attribute->getName(), $AttributeLabel, $backendType));
					$insertProductAttributeSQL->execute(array($productData['entity_id'], $AttributeID, $AttributeValue));
				}
			}
		}

		$hasImage = false;
		$product->load('media_gallery');

		$primaryImage = isset($productData['image']) ? $productData['image'] : '';

		foreach ($product->getMediaGallery('images') as $image) {

			$imgURL = $product->getMediaConfig()->getMediaUrl($image['file']);

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

			$insertImageSQL->execute(array($productData['entity_id'], $imgURL, $tag, $sequence));

			$hasImage = true;
		}

		if($type == 'simple' &&
			!$hasImage)
		{
			$baseSequence = 0;

			$groupedparentsid = Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild($product['entity_id']);
			foreach ($groupedparentsid as $parentid) {

				$groupedProduct = Mage::getModel('catalog/product')->load($parentid);

				$maxSequence = 0;
				$baseImageFound = false;

				foreach ($groupedProduct->getMediaGallery('images') as $image) {

					$imgURL = $groupedProduct->getMediaConfig()->getMediaUrl($image['file']);

					if(!$baseImageFound && ($image['file'] == $groupedProduct->getImage()))
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

					$insertImageSQL->execute(array($productData['entity_id'], $imgURL, $tag, $sequence));
				}

				$baseSequence = $maxSequence;
			}
		}

		if($type == 'simple')
		{
			$this->productsProcessed[] = $productData['entity_id'];

			if($productData['entity_id'] > $this->currentEntityId)
				$this->currentEntityId = $productData['entity_id'];
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

	public function SyncChunk($syncDb, $simpleCount, $configurableCount, $storeId, $first)
	{
		$store = Mage::app()->getStore($storeId);

		$db = $this->GetSyncDb($syncDb);

		//Log Table for data visibility
		$insertlog = $db->prepare('INSERT INTO Log(ID, Type, Content) VALUES(?,?,?)');

		$insertCategory = $db->prepare('INSERT OR REPLACE INTO Category(ExternalReference, Name, ParentExternalReference, LastModified, Enabled, Sequence) VALUES(?,?,?,?,?,?)');
		$insertCategoryProduct = $db->prepare('INSERT OR IGNORE INTO CategoryProduct(ProductExternalReference, CategoryExternalReference, Sequence) VALUES(?,?,?)');
		$insertProduct = $db->prepare('INSERT INTO Product(ExternalReference, Code, Name, Price, ListPrice, TaxClass, Description, Enabled, StockControl, StockLevel, Weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
		$insertSKU = $db->prepare('INSERT OR IGNORE INTO SKU(ExternalReference, Code, ProductExternalReference, Name, StockControl, StockLevel, Price, Enabled) VALUES(?,?,?,?,?,?,?,?)');
		$insertSKUMatrix = $db->prepare('INSERT INTO SKUMatrix(SKUExternalReference, Code, OptionName, OptionValue, ProductOptionExternalReference, ProductOptionValueExternalReference) VALUES(?,?,?,?,?,?)');
		$insertImage = $db->prepare('INSERT INTO ProductImage(ProductExternalReference, URL, Tag, Sequence) VALUES(?,?,?,?)');
		$insertSKUImage = $db->prepare('INSERT INTO SKUImage(SKUExternalReference, URL, Tag, Sequence) VALUES(?,?,?,?)');
		$insertProductOption = $db->prepare('INSERT INTO ProductOption (ExternalReference, Sequence, ProductExternalReference) VALUES (?,?,?)');
		$insertProductOptionValue = $db->prepare('INSERT INTO ProductOptionValue (ExternalReference, Sequence, ProductOptionExternalReference, ProductExternalReference) VALUES (?,?,?,?)');
		$insertProductHTML = $db->prepare('INSERT OR IGNORE INTO ProductHTML(ProductExternalReference, Tag, HTML) VALUES (?, ?, ?)');
		$insertAttribute = $db->prepare('INSERT OR REPLACE INTO Attribute(ID, Code, Label, Type) VALUES (?, ?, ?, ?)');
		$insertProductAttribute = $db->prepare('INSERT OR IGNORE INTO ProductAttributeValue(ProductExternalReference, AttributeID, Value) VALUES (?, ?, ?)');
		$insertOrders = $db->prepare('INSERT OR REPLACE INTO [Order] (ID, Status, PaymentDate, ShipmentDate, Carrier, TrackingNumber) VALUES (?, ?, ?, ?, ?, ?)');

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

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		$this->currentEntityId = $db->query('SELECT entity_id FROM Progress')->fetchColumn();
		if(!$this->currentEntityId)
			$this->currentEntityId = 0;

		$state = $db->query('SELECT State FROM Progress')->fetchColumn();

		if(!$state)
		{
			// build configuration table
			foreach ($config as $key => $value) {
				$insertConfiguration->execute(array($key, $value));
			}

			$insertConfiguration->execute(array('currency', $store->getBaseCurrencyCode()));

			// Categories
			$categories = Mage::getModel('catalog/category')->getCollection()
								->addAttributeToSelect(array('name', 'image', 'is_active', 'updated_at', 'parent_id', 'position'), 'left');

			Mage::getSingleton('core/resource_iterator')->walk($categories->getSelect(), array(array($this, 'SyncCategoryData')), array( 'db' => $db, 'preparedStatement' => $insertCategory, 'store' => $store ));

			$state = 'configurable';
		}

		$this->productsProcessed = array();
		$this->ordersProcessed = array();

		if($state == 'configurable')
		{
			// Configurable products
			$configurableProducts = Mage::getModel('catalog/product')->getCollection()
								->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'status', 'tax_class_id', 'weight'), 'left')
								->addAttributeToFilter('type_id', array('eq' => 'configurable'))
								->addAttributeToFilter('entity_id', array('gt' => $this->currentEntityId));

			$configurableProducts->getSelect()->order('entity_id')->limit($configurableCount);
			$configurableProducts->setOrder('entity_id', 'ASC');

			Mage::getSingleton('core/resource_iterator')->walk($configurableProducts->getSelect(), array(array($this, 'SyncConfigurableProductData')), array( 'type' => 'configurable', 'db' => $db, 'preparedStatement' => $insertProduct, 'preparedskuStatement' => $insertSKU, 'preparedskumatrixStatement' => $insertSKUMatrix, 'preparedcategoryproductStatement' => $insertCategoryProduct, 'preparedimageStatement' => $insertImage, 'preparedskuimageStatement' => $insertSKUImage, 'preparedproductoptionStatement' => $insertProductOption,  'preparedproductoptionvalueStatement' => $insertProductOptionValue, 'preparedproducthtmlStatement' => $insertProductHTML, 'preparedattributeStatement' => $insertAttribute, 'preparedproductattributeStatement' => $insertProductAttribute, 'store' => $store ));

			if(!empty($this->productsProcessed))
			{
				$db->exec('DELETE FROM Product WHERE ExternalReference IN ('.implode(',', $this->productsProcessed).') AND ExternalReference NOT IN (SELECT ProductExternalReference FROM SKU WHERE ProductExternalReference IS NOT NULL)');
				$db->exec('INSERT OR REPLACE INTO Progress (Sentinel, State, entity_id) VALUES (1, \'configurable\', '.$this->currentEntityId.')');
			}
			else
			{
				$state = 'simple';
				$this->currentEntityId = 0;
			}
		}

		if($state == 'simple')
		{
			// Simple Products not participating as configurable skus
			$superLinkName = Mage::getSingleton('core/resource')->getTableName('catalog/product_super_link');

			$simpleProducts = Mage::getModel('catalog/product')->getCollection()
								->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'short_description', 'price', 'special_price', 'status', 'tax_class_id', 'weight'), 'left')
								->addAttributeToFilter('type_id', array('eq' => 'simple'))
								->addAttributeToFilter('entity_id', array('gt' => $this->currentEntityId));

			$simpleProducts->getSelect()->where('`e`.entity_id NOT IN (SELECT product_id FROM '.$superLinkName.')')->order('entity_id')->limit($simpleCount);
			$simpleProducts->setOrder('entity_id', 'ASC');

			Mage::getSingleton('core/resource_iterator')->walk($simpleProducts->getSelect(), array(array($this, 'SyncSimpleProductData')), array( 'type' => 'simple', 'db' => $db, 'preparedStatement' => $insertProduct, 'preparedcategoryproductStatement' => $insertCategoryProduct, 'preparedimageStatement' => $insertImage, 'preparedproducthtmlStatement' => $insertProductHTML, 'preparedattributeStatement' => $insertAttribute, 'preparedproductattributeStatement' => $insertProductAttribute, 'store' => $store ));

			if(!empty($this->productsProcessed))
			{
				$db->exec('INSERT OR REPLACE INTO Progress (Sentinel, State, entity_id) VALUES (1, \'simple\', '.$this->currentEntityId.')');
			}
			else
			{
				$state = 'orders';
				$this->currentEntityId = 0;
			}
		}

		if($state == 'orders')
		{
			$orderStoreId = $storeId;
			if($storeId == 0)
			{
				$stores = Mage::getModel('core/store')->getCollection()
							->addFieldToFilter('is_active', array('neq' => 0))
							->setOrder('store_id', 'ASC');

				$orderStoreId = $stores->getFirstItem()->getId();
			}

			$coreResource = Mage::getSingleton('core/resource');

			$invoiceName = $coreResource->getTableName('sales/invoice');
			$shipmentName = $coreResource->getTableName('sales/shipment');
			$shipmentTrackName = $coreResource->getTableName('sales/shipment_track');

			$ts = Mage::getModel('core/date')->gmtTimestamp();
			$ts -= 7776000; // 90 days

			$orders = Mage::getModel('sales/order')->getCollection()
						->addFieldToSelect(array('codisto_orderid', 'status'))
						->addAttributeToFilter('codisto_orderid', array('gt' => (int)$this->currentEntityId ))
						->addAttributeToFilter('main_table.store_id', array('eq' => $orderStoreId ))
						->addAttributeToFilter('main_table.updated_at', array('gteq' => date('Y-m-d H:i:s', $ts)));
			$orders->getSelect()->joinLeft( array('i' => $invoiceName), 'i.order_id = main_table.entity_id AND i.state = 2', array('pay_date' => 'MIN(i.created_at)'));
			$orders->getSelect()->joinLeft( array('s' => $shipmentName), 's.order_id = main_table.entity_id', array('ship_date' => 'MIN(s.created_at)'));
			$orders->getSelect()->joinLeft( array('t' => $shipmentTrackName), 't.order_id = main_table.entity_id', array('carrier' => 'GROUP_CONCAT(COALESCE(t.title, \'\') SEPARATOR \',\')', 'track_number' => 'GROUP_CONCAT(COALESCE(t.track_number, \'\') SEPARATOR \',\')'));
			$orders->getSelect()->group(array('main_table.entity_id', 'main_table.codisto_orderid', 'main_table.status'));
			$orders->getSelect()->limit(1000);
			$orders->setOrder('entity_id', 'ASC');

			Mage::getSingleton('core/resource_iterator')->walk($orders->getSelect(), array(array($this, 'SyncOrderData')), array( 'db' => $db, 'preparedStatement' => $insertOrders, 'store' => $store ));

			$db->exec('INSERT OR REPLACE INTO Progress (Sentinel, State, entity_id) VALUES (1, \'orders\', '.$this->currentEntityId.')');
		}

		$db->exec('COMMIT TRANSACTION');

		if((empty($this->productsProcessed) && empty($this->ordersProcessed)) || $first)
		{
			return 'complete';
		}
		else
		{
			return 'pending';
		}
	}

	public function ProductTotals($storeId) {
		
		$store = Mage::app()->getStore($storeId);

		$configurableProducts = Mage::getModel('catalog/product')->getCollection()
							->addAttributeToSelect(array('entity_id'), 'left')
							->addAttributeToFilter('type_id', array('eq' => 'configurable'));

		$configurablecount = $configurableProducts->getSize();
		
		$simpleProducts = Mage::getModel('catalog/product')->getCollection()
							->addAttributeToSelect(array('entity_id'), 'left')
							->addAttributeToFilter('type_id', array('eq' => 'simple'));
		
		$simplecount = $simpleProducts->getSize();
		
		return array('simplecount' => $simplecount, 'configurablecount' => $configurablecount);
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


		$taxCalcs = Mage::getModel('tax/calculation')->getCollection()
				->addFieldToFilter('customer_tax_class_id', array('eq' => $ebayGroup->getTaxClassId()));

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
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		$db->exec('PRAGMA synchronous=0');
		$db->exec('PRAGMA temp_store=2');
		$db->exec('PRAGMA page_size=65536');
		$db->exec('PRAGMA encoding=\'UTF-8\'');
		$db->exec('PRAGMA cache_size=15000');
		$db->exec('PRAGMA soft_heap_limit=67108864');
		$db->exec('PRAGMA journal_mode=MEMORY');

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');
		$db->exec('CREATE TABLE IF NOT EXISTS Progress(entity_id integer NOT NULL, State text NOT NULL, Sentinel integer NOT NULL PRIMARY KEY AUTOINCREMENT, CHECK(Sentinel=1))');
		$db->exec('CREATE TABLE IF NOT EXISTS Category(ExternalReference text NOT NULL PRIMARY KEY, ParentExternalReference text NOT NULL, '.
							'Name text NOT NULL, LastModified datetime NOT NULL, Enabled bit NOT NULL, Sequence integer NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS CategoryProduct (CategoryExternalReference text NOT NULL, ProductExternalReference text NOT NULL, Sequence integer NOT NULL, PRIMARY KEY(CategoryExternalReference, ProductExternalReference))');
		$db->exec('CREATE TABLE IF NOT EXISTS Product (ExternalReference text NOT NULL PRIMARY KEY, Code text NULL, Name text NOT NULL, Price real NOT NULL, ListPrice real NOT NULL, TaxClass text NOT NULL, Description text NOT NULL, '.
					'Enabled bit NOT NULL,  '.
					'StockControl bit NOT NULL, StockLevel integer NOT NULL, '.
					'Weight real NULL)');

		$db->exec('CREATE TABLE IF NOT EXISTS ProductOption (ExternalReference integer NOT NULL, Sequence integer NOT NULL, ProductExternalReference integer NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductOption_ProductExternalReference ON ProductOption(ProductExternalReference)');
		$db->exec('CREATE TABLE IF NOT EXISTS ProductOptionValue (ExternalReference integer NOT NULL, Sequence integer NOT NULL, ProductOptionExternalReference integer NOT NULL, ProductExternalReference integer NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductOptionValue_ProductOptionExternalReference ON ProductOptionValue(ProductOptionExternalReference)');

		$db->exec('CREATE TABLE IF NOT EXISTS SKU (ExternalReference text NOT NULL PRIMARY KEY, Code text NULL, ProductExternalReference text NOT NULL, Name text NOT NULL, StockControl bit NOT NULL, StockLevel integer NOT NULL, Price real NOT NULL, Enabled bit NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_SKU_ProductExternalReference ON SKU(ProductExternalReference)');
		$db->exec('CREATE TABLE IF NOT EXISTS SKUMatrix (SKUExternalReference text NOT NULL, Code text NULL, OptionName text NOT NULL, OptionValue text NOT NULL, ProductOptionExternalReference, ProductOptionValueExternalReference)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_SKUMatrix_SKUExternalReference ON SKUMatrix(SKUExternalReference)');

		$db->exec('CREATE TABLE IF NOT EXISTS ProductImage (ProductExternalReference text NOT NULL, URL text NOT NULL, Tag text NOT NULL DEFAULT \'\', Sequence integer NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductImage_ProductExternalReference ON ProductImage(ProductExternalReference)');
		$db->exec('CREATE TABLE IF NOT EXISTS SKUImage (SKUExternalReference text NOT NULL, URL text NOT NULL, Tag text NOT NULL DEFAULT \'\', Sequence integer NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_SKUImage_SKUExternalReference ON SKUImage(SKUExternalReference)');

		$db->exec('CREATE TABLE IF NOT EXISTS ProductHTML (ProductExternalReference text NOT NULL, Tag text NOT NULL, HTML text NOT NULL, PRIMARY KEY (ProductExternalReference, Tag))');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductHTML_ProductExternalReference ON ProductHTML(ProductExternalReference)');

		$db->exec('CREATE TABLE IF NOT EXISTS Attribute (ID integer NOT NULL PRIMARY KEY, Code text NOT NULL, Label text NOT NULL, Type text NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS ProductAttributeValue (ProductExternalReference integer NOT NULL, AttributeID integer NOT NULL, Value any, PRIMARY KEY (ProductExternalReference, AttributeID))');

		$db->exec('CREATE TABLE IF NOT EXISTS TaxClass (ID integer NOT NULL PRIMARY KEY, Type text NOT NULL, Name text NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS TaxCalculation(ID integer NOT NULL PRIMARY KEY, TaxRateID integer NOT NULL, TaxRuleID integer NOT NULL, ProductTaxClassID integer NOT NULL, CustomerTaxClassID integer NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS TaxCalculationRule(ID integer NOT NULL PRIMARY KEY, Code text NOT NULL, Priority integer NOT NULL, Position integer NOT NULL, CalculateSubTotal bit NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS TaxCalculationRate(ID integer NOT NULL PRIMARY KEY, Country text NOT NULL, RegionID integer NOT NULL, RegionName text NULL, RegionCode text NULL, PostCode text NOT NULL, Code text NOT NULL, Rate real NOT NULL, IsRange bit NULL, ZipFrom text NULL, ZipTo text NULL)');


		$db->exec('CREATE TABLE IF NOT EXISTS Store(ID integer NOT NULL PRIMARY KEY, Code text NOT NULL, Name text NOT NULL, Currency text NOT NULL)');
		$db->exec('CREATE TABLE IF NOT EXISTS StoreMerchant(StoreID integer NOT NULL, MerchantID integer NOT NULL, PRIMARY KEY (StoreID, MerchantID))');

		$db->exec('CREATE TABLE IF NOT EXISTS [Order](ID integer NOT NULL PRIMARY KEY, Status text NOT NULL, PaymentDate datetime NULL, ShipmentDate datetime NULL, Carrier text NOT NULL, TrackingNumber text NOT NULL)');

		$db->exec('CREATE TABLE IF NOT EXISTS Configuration (configuration_id integer, configuration_title text, configuration_key text, configuration_value text, configuration_description text, configuration_group_id integer, sort_order integer, last_modified datetime, date_added datetime, use_function text, set_function text)');
		$db->exec('CREATE TABLE IF NOT EXISTS Log (ID, Type text NOT NULL, Content text NOT NULL)');

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

		$db->exec('COMMIT TRANSACTION');

		return $db;
	}

	private function GetTemplateDb($templateDb)
	{
		$db = new PDO('sqlite:' . $templateDb);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		$db->exec('PRAGMA synchronous=0');
		$db->exec('PRAGMA temp_store=2');
		$db->exec('PRAGMA page_size=65536');
		$db->exec('PRAGMA encoding=\'UTF-8\'');
		$db->exec('PRAGMA cache_size=15000');
		$db->exec('PRAGMA soft_heap_limit=67108864');
		$db->exec('PRAGMA journal_mode=MEMORY');

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');
		$db->exec('CREATE TABLE IF NOT EXISTS File(Name text NOT NULL PRIMARY KEY, Content blob NOT NULL, LastModified datetime NOT NULL, Changed bit NOT NULL DEFAULT -1)');
		$db->exec('COMMIT TRANSACTION');

		return $db;
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
