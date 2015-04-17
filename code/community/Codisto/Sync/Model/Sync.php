<?php

class Codisto_Sync_Model_Sync
{
	private $currentProductId;
	private $productsProcessed;
	
	private $useTaxHelper = true;
	
	private $taxCalculation;
	private $rateRequest;
	
	private $cmsHelper;
	
	public function __construct()
	{
		$edition = Mage::getEdition();

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

	
	public function UpdateCategory($syncDb, $id)
	{
		$store = Mage::app()->getStore('default');
		
		$db = $this->GetSyncDb($syncDb);

		$insertCategory = $db->prepare('INSERT OR REPLACE INTO Category(ExternalReference, Name, ParentExternalReference, LastModified, Enabled, Sequence) VALUES(?,?,?,?,?,?)');

		$category = Mage::getModel('catalog/category')->getCollection()
							->addAttributeToSelect(array('name', 'image', 'is_active'), 'left')
							->addAttributeToFilter('entity_id', array('eq' => $id));

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');

		Mage::getSingleton('core/resource_iterator')->walk($category->getSelect(), array(array($this, 'SyncCategory')), array( 'db' => $db, 'preparedStatement' => $insertCategory, 'store' => $store ));
		
		$db->exec('COMMIT TRANSACTION');
	}
	
	public function DeleteCategory($syncDb, $id)
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

	public function UpdateProducts($syncDb, $ids)
	{
		$store = Mage::app()->getStore('default');
		
		$db = $this->GetSyncDb($syncDb);
		
		$insertCategoryProduct = $db->prepare('INSERT OR IGNORE INTO CategoryProduct(ProductExternalReference, CategoryExternalReference, Sequence) VALUES(?,?,?)');
		$insertProduct = $db->prepare('INSERT INTO Product(ExternalReference, Code, Name, Price, ListPrice, TaxClass, Description, Enabled, StockControl, StockLevel, Weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
		$insertSKU = $db->prepare('INSERT OR IGNORE INTO SKU(ExternalReference, Code, ProductExternalReference, Name, StockControl, StockLevel, Price, Enabled) VALUES(?,?,?,?,?,?,?,?)');
		$insertSKUMatrix = $db->prepare('INSERT INTO SKUMatrix(SKUExternalReference, Code, OptionName, OptionValue) VALUES(?,?,?,?)');
		$insertImage = $db->prepare('INSERT INTO ProductImage(ProductExternalReference, URL, Tag, Sequence) VALUES(?,?,?,?)');
		$insertSKUImage = $db->prepare('INSERT INTO SKUImage(SKUExternalReference, URL, Tag, Sequence) VALUES(?,?,?,?)');
		
		$this->productsProcessed = array();
		
		$superLinkName = Mage::getSingleton('core/resource')->getTableName('catalog/product_super_link');
		
		// Configurable products
		$configurableProducts = Mage::getModel('catalog/product')->getCollection()
							->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'price', 'special_price', 'status', 'tax_class_id', 'weight'), 'left')
							->addAttributeToFilter('type_id', array('eq' => 'configurable'));

		$configurableProducts->getSelect()->where('`e`.entity_id IN ('.implode(',', $ids).') OR `e`.entity_id IN (SELECT parent_id FROM '.$superLinkName.' WHERE product_id IN ('.implode(',', $ids).'))');

		// Simple Products not participating as configurable skus
		$simpleProducts = Mage::getModel('catalog/product')->getCollection()
							->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'price', 'special_price', 'status', 'tax_class_id', 'weight'), 'left')
							->addAttributeToFilter('type_id', array('eq' => 'simple'))
							->addAttributeToFilter('entity_id', array('in' => $ids));

		$simpleProducts->getSelect()->where('`e`.entity_id NOT IN (SELECT product_id FROM '.$superLinkName.')');

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');
		
		$db->exec('DELETE FROM Product WHERE ExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM ProductImage WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM SKUMatrix WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference IN ('.implode(',', $ids).'))');
		$db->exec('DELETE FROM SKUImage WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference IN ('.implode(',', $ids).'))');
		$db->exec('DELETE FROM SKU WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		$db->exec('DELETE FROM CategoryProduct WHERE ProductExternalReference IN ('.implode(',', $ids).')');
		
		Mage::getSingleton('core/resource_iterator')->walk($configurableProducts->getSelect(), array(array($this, 'SyncConfigurableProduct')), array( 'type' => 'configurable', 'db' => $db, 'preparedStatement' => $insertProduct, 'preparedskuStatement' => $insertSKU, 'preparedskumatrixStatement' => $insertSKUMatrix, 'preparedcategoryproductStatement' => $insertCategoryProduct, 'preparedimageStatement' => $insertImage, 'preparedskuimageStatement' => $insertSKUImage, 'store' => $store ));

		$db->exec('DELETE FROM Product WHERE ExternalReference IN ('.implode(',', $ids).') AND ExternalReference NOT IN (SELECT ProductExternalReference FROM SKU WHERE ProductExternalReference IS NOT NULL)');

		Mage::getSingleton('core/resource_iterator')->walk($simpleProducts->getSelect(), array(array($this, 'SyncSimpleProduct')), array( 'type' => 'simple', 'db' => $db, 'preparedStatement' => $insertProduct, 'preparedcategoryproductStatement' => $insertCategoryProduct, 'preparedimageStatement' => $insertImage, 'store' => $store ));
		
		$db->exec('COMMIT TRANSACTION');
	}
	
	public function DeleteProduct($syncDb, $id)
	{
		$db = $this->GetSyncDb($syncDb);
		
		$db->exec('BEGIN EXCLUSIVE TRANSACTION');
		
		$db->exec(	'CREATE TABLE IF NOT EXISTS ProductDelete(ExternalReference text NOT NULL PRIMARY KEY);'.
					'INSERT OR IGNORE INTO ProductDelete VALUES('.$id.');'.
					'DELETE FROM Product WHERE ExternalReference = '.$id.';'.
					'DELETE FROM ProductImage WHERE ProductExternalReference = '.$id.';'.
					'DELETE FROM SKUMatrix WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference = '.$id.');'.
					'DELETE FROM SKUImage WHERE SKUExternalReference IN (SELECT ExternalReference FROM SKU WHERE ProductExternalReference = '.$id.');'.
					'DELETE FROM SKU WHERE ProductExternalReference = '.$id.';'.
					'DELETE FROM CategoryProduct WHERE ProductExternalReference = '.$id);
		
		$db->exec('COMMIT TRANSACTION');
	}
	
	public function SyncCategory($args)
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
	
	public function SyncSKU($args)
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
		
		$totalPrice = $product->getFinalPrice();

		foreach ($attributes as $attribute) {
			
			$productAttribute = $attribute->getProductAttribute();
			
			$attributeOptionId = $product->getData($productAttribute->getAttributeCode());

			//add the price adjustment to the total price of the simple product
			if (isset($pricesByAttributeValues[$attributeOptionId])) {
				$totalPrice += $pricesByAttributeValues[$attributeOptionId];
			}
		}

		$price = $this->getExTaxPrice($product, $totalPrice, $store);
		
		$data = array();
		$data[] = $skuData['entity_id'];
		$data[] = $skuData['sku'];
		$data[] = $args['parent_id'];
		$data[] = $skuData['name'];
		$data[] = !isset($stockData['use_config_manage_stock']) || $stockData['use_config_manage_stock'] == 0 ?
					(isset($stockData['manage_stock']) && $stockData['manage_stock'] ? -1 : 0) :
					(Mage::getStoreConfig('cataloginventory/item_options/manage_stock') ? -1 : 0);
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
			
			$attributeName = $productAttribute->getFrontendLabel();
			$attributeValue = $product->getAttributeText($productAttribute->getAttributeCode());
			
			$insertSKUMatrixSQL->execute(array($skuData['entity_id'], '', $attributeName, $attributeValue));
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
	
	public function SyncConfigurableProduct($args)
	{
		$productData = $args['row'];

		$store = $args['store'];
		$db = $args['db'];
		
		$insertSQL = $args['preparedskuStatement'];
		$insertCategorySQL = $args['preparedcategoryproductStatement'];
		$insertImageSQL = $args['preparedskuimageStatement'];
		$insertSKUMatrixSQL = $args['preparedskumatrixStatement'];
		
		$productData['sku'] = null;
		
		$this->SyncSimpleProduct(array_merge($args, array('row' => $productData)));
		
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
		}

		$childProducts = $configurableData->getUsedProductCollection()
							->addAttributeToSelect(array('name', 'image', 'status', 'price', 'special_price', 'tax_class_id'), 'left');
		
		Mage::getSingleton('core/resource_iterator')->walk($childProducts->getSelect(), array(array($this, 'SyncSKU')), array( 'parent_id' => $productData['entity_id'], 'attributes' => $configurableAttributes, 'prices' => $pricesByAttributeValues, 'db' => $db, 'preparedStatement' => $insertSQL, 'preparedskumatrixStatement' => $insertSKUMatrixSQL, 'preparedcategoryproductStatement' => $insertCategorySQL, 'preparedimageStatement' => $insertImageSQL, 'store' => $store ));
		
		$this->productsProcessed[] = $productData['entity_id'];
		
		if($productData['entity_id'] > $this->currentProductId)
			$this->currentProductId = $productData['entity_id'];
	}
	
	public function SyncSimpleProduct($args)
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

		$price = $this->getExTaxPrice($product, $product->getFinalPrice(), $store);
		$listPrice = $this->getExTaxPrice($product, $product->getPrice(), $store);
				
		$description = $this->cmsHelper->getBlockTemplateProcessor()->filter(preg_replace('/^\s+|\s+$/', '', $productData['description']));
		if($type == 'simple' &&
			$description == '')
		{
			$parentIds = Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild($productData['entity_id']);
			
			if($parentIds)
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

		$data = array();
		
		$data[] = $productData['entity_id'];
		$data[] = $productData['sku'];
		$data[] = $productData['name'];
		$data[] = $price;
		$data[] = $listPrice;
		$data[] = isset($productData['tax_class_id']) && $productData['tax_class_id'] ? $productData['tax_class_id'] : '';
		$data[] = $description;
		$data[] = $productData['status'] != 1 ? 0 : -1;
		$data[] = !isset($stockData['use_config_manage_stock']) || $stockData['use_config_manage_stock'] == 0 ?
					(isset($stockData['manage_stock']) && $stockData['manage_stock'] ? -1 : 0) :
					(Mage::getStoreConfig('cataloginventory/item_options/manage_stock') ? -1 : 0);
		$data[] = isset($stockData['qty']) && is_numeric($stockData['qty']) ? (int)$stockData['qty'] : 1;
		$data[] = isset($productData['weight']) && is_numeric($productData['weight']) ? (float)$productData['weight'] : $productData['weight'];
				
		if($db->query('SELECT CASE WHEN EXISTS(SELECT 1 FROM Product WHERE ExternalReference = '.$productData['entity_id'].') THEN 1 ELSE 0 END')->fetchColumn())
			return;
				
		$insertSQL->execute($data);
		
		$categoryIds = $product->getCategoryIds();
		foreach ($categoryIds as $categoryId) {
			$insertCategorySQL->execute(array($productData['entity_id'], $categoryId, 0));
		}
		
		$hasImage = false;
		$product->load('media_gallery');
		foreach ($product->getMediaGallery('images') as $image) {

			$imgURL = $product->getMediaConfig()->getMediaUrl($image['file']);
			
			if($image['file'] == $productData['image'])
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
			$options = $product->getOptions();
			
			foreach ($options as $option) {
			
				if($option->getType() == 'select')
				{
					syslog(1, $option->getName());
					
					foreach ($option->getValues() as $value)
					{
						syslog(1, '    '.$value->getTitle());
					}
				}
			}
		}
		
		if($type == 'simple')
		{
			$this->productsProcessed[] = $productData['entity_id'];
			
			if($productData['entity_id'] > $this->currentProductId)
				$this->currentProductId = $productData['entity_id'];
		}
	}
	
	public function SyncChunk($syncDb)
	{
		$store = Mage::app()->getStore('default');
		
		$db = $this->GetSyncDb($syncDb);
				
		//Log Table for data visibility
		$insertlog = $db->prepare('INSERT INTO Log(ID, Type, Content) VALUES(?,?,?)');
					
		$insertCategory = $db->prepare('INSERT OR REPLACE INTO Category(ExternalReference, Name, ParentExternalReference, LastModified, Enabled, Sequence) VALUES(?,?,?,?,?,?)');
		$insertCategoryProduct = $db->prepare('INSERT OR IGNORE INTO CategoryProduct(ProductExternalReference, CategoryExternalReference, Sequence) VALUES(?,?,?)');
		$insertProduct = $db->prepare('INSERT INTO Product(ExternalReference, Code, Name, Price, ListPrice, TaxClass, Description, Enabled, StockControl, StockLevel, Weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
		$insertSKU = $db->prepare('INSERT OR IGNORE INTO SKU(ExternalReference, Code, ProductExternalReference, Name, StockControl, StockLevel, Price, Enabled) VALUES(?,?,?,?,?,?,?,?)');
		$insertSKUMatrix = $db->prepare('INSERT INTO SKUMatrix(SKUExternalReference, Code, OptionName, OptionValue) VALUES(?,?,?,?)');
		$insertImage = $db->prepare('INSERT INTO ProductImage(ProductExternalReference, URL, Tag, Sequence) VALUES(?,?,?,?)');
		$insertSKUImage = $db->prepare('INSERT INTO SKUImage(SKUExternalReference, URL, Tag, Sequence) VALUES(?,?,?,?)');
		
		// Configuration
		$config = array(
			'baseurl' => Mage::getBaseUrl(),
			'skinurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN),
			'mediaurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA),
			'jsurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_JS),
			'storeurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB),
			'logourl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN) . 'frontend/' .
				Mage::getDesign()->getTheme('frontend') . '/' . Mage::getDesign()->getTheme('frontend') . '/' .
				Mage::getStoreConfig('design/header/logo_src'),
			'theme' => Mage::getDesign()->getTheme('frontend')
		);
		$insertConfiguration = $db->prepare('INSERT INTO Configuration(configuration_key, configuration_value) VALUES(?,?)');

		foreach ($config as $key => $value) {
			$insertConfiguration->execute(array($key, $value));
		}

		$db->exec('BEGIN EXCLUSIVE TRANSACTION');
		
		$this->currentProductId = $db->query('SELECT entity_id FROM Progress')->fetchColumn();
		if(!$this->currentProductId)
			$this->currentProductId = 0;
			
		$state = $db->query('SELECT State FROM Progress')->fetchColumn();

		if(!$state)
		{
			// Categories
			$categories = Mage::getModel('catalog/category')->getCollection()
								->addAttributeToSelect(array('name', 'image', 'is_active'), 'left');
	
			Mage::getSingleton('core/resource_iterator')->walk($categories->getSelect(), array(array($this, 'SyncCategory')), array( 'db' => $db, 'preparedStatement' => $insertCategory, 'store' => $store ));
			
			$state = 'configurable';
		}
		
		$this->productsProcessed = array();
		
		if($state == 'configurable')
		{
			// Configurable products
			$configurableProducts = Mage::getModel('catalog/product')->getCollection()
								->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'price', 'special_price', 'status', 'tax_class_id', 'weight'), 'left')
								->addAttributeToFilter('type_id', array('eq' => 'configurable'))
								->addAttributeToFilter('entity_id', array('gt' => $this->currentProductId));
								
			$configurableProducts->getSelect()->order('entity_id')->limit(6);
	
			Mage::getSingleton('core/resource_iterator')->walk($configurableProducts->getSelect(), array(array($this, 'SyncConfigurableProduct')), array( 'type' => 'configurable', 'db' => $db, 'preparedStatement' => $insertProduct, 'preparedskuStatement' => $insertSKU, 'preparedskumatrixStatement' => $insertSKUMatrix, 'preparedcategoryproductStatement' => $insertCategoryProduct, 'preparedimageStatement' => $insertImage, 'preparedskuimageStatement' => $insertSKUImage, 'store' => $store ));
	
			if(!empty($this->productsProcessed))
			{
				$db->exec('DELETE FROM Product WHERE ExternalReference IN ('.implode(',', $this->productsProcessed).') AND ExternalReference NOT IN (SELECT ProductExternalReference FROM SKU WHERE ProductExternalReference IS NOT NULL)');
				$db->exec('INSERT OR REPLACE INTO Progress (Sentinel, State, entity_id) VALUES (1, \'configurable\', '.$this->currentProductId.')');
			}
			else
			{
				$state = 'simple';
				$this->currentProductId = 0;
			}
		}
		
		if($state == 'simple')
		{
			// Simple Products not participating as configurable skus
			$superLinkName = Mage::getSingleton('core/resource')->getTableName('catalog/product_super_link');
			
			$simpleProducts = Mage::getModel('catalog/product')->getCollection()
								->addAttributeToSelect(array('entity_id', 'sku', 'name', 'image', 'description', 'price', 'special_price', 'status', 'tax_class_id', 'weight'), 'left')
								->addAttributeToFilter('type_id', array('eq' => 'simple'))
								->addAttributeToFilter('entity_id', array('gt' => $this->currentProductId));
	
			$simpleProducts->getSelect()->where('`e`.entity_id NOT IN (SELECT product_id FROM '.$superLinkName.')')->order('entity_id')->limit(250);
	
			Mage::getSingleton('core/resource_iterator')->walk($simpleProducts->getSelect(), array(array($this, 'SyncSimpleProduct')), array( 'type' => 'simple', 'db' => $db, 'preparedStatement' => $insertProduct, 'preparedcategoryproductStatement' => $insertCategoryProduct, 'preparedimageStatement' => $insertImage, 'store' => $store ));

			$db->exec('INSERT OR REPLACE INTO Progress (Sentinel, State, entity_id) VALUES (1, \'simple\', '.$this->currentProductId.')');
		}
		
		$db->exec('COMMIT TRANSACTION');
		
		if(empty($this->productsProcessed))
		{
			return 'complete';
		}
		else
		{
			return 'pending';
		}
	}

	public function Sync($syncDb)
	{
		ini_set('max_execution_time', -1);

		$result = $this->SyncChunk($syncDb);
		while($result != 'complete')
		{
			$result = $this->SyncChunk($syncDb);
		}
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
		$db->exec('CREATE TABLE IF NOT EXISTS SKU (ExternalReference text NOT NULL PRIMARY KEY, Code text NULL, ProductExternalReference text NOT NULL, Name text NOT NULL, StockControl bit NOT NULL, StockLevel integer NOT NULL, Price real NOT NULL, Enabled bit NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_SKU_ProductExternalReference ON SKU(ProductExternalReference)');
		$db->exec('CREATE TABLE IF NOT EXISTS SKUMatrix (SKUExternalReference text NOT NULL, Code text NULL, OptionName text NOT NULL, OptionValue text NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_SKUMatrix_SKUExternalReference ON SKUMatrix(SKUExternalReference)');
			
		$db->exec('CREATE TABLE IF NOT EXISTS ProductImage (ProductExternalReference text NOT NULL, URL text NOT NULL, Tag text NOT NULL DEFAULT \'\', Sequence integer NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_ProductImage_ProductExternalReference ON ProductImage(ProductExternalReference)');
		$db->exec('CREATE TABLE IF NOT EXISTS SKUImage (SKUExternalReference text NOT NULL, URL text NOT NULL, Tag text NOT NULL DEFAULT \'\', Sequence integer NOT NULL)');
		$db->exec('CREATE INDEX IF NOT EXISTS IX_SKUImage_SKUExternalReference ON SKUImage(SKUExternalReference)');
		
		$db->exec('CREATE TABLE IF NOT EXISTS Configuration (configuration_id integer, configuration_title text, configuration_key text, configuration_value text, configuration_description text, configuration_group_id integer, sort_order integer, last_modified datetime, date_added datetime, use_function text, set_function text)');
		$db->exec('CREATE TABLE IF NOT EXISTS Log (ID, Type text NOT NULL, Content text NOT NULL)');
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
