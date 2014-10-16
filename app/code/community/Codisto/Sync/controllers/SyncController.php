<?php
class Codisto_Sync_SyncController extends Mage_Core_Controller_Front_Action
{
	var $config = array();

	public function indexAction()
	{
		$this->getConfig();

		if (isset($_SERVER['HTTP_X_SYNC'])) {
			if (!isset($_SERVER['HTTP_X_ACTION']))
				$_SERVER['HTTP_X_ACTION'] = "";

			switch ($_SERVER['HTTP_X_ACTION']) {
				case "HELLO":
					die("Mobile");
				case "GET":
					if ($this->checkHash($this->config['HostKey'], $_SERVER['HTTP_X_NONCE'], $_SERVER['HTTP_X_HASH'])) {
						$this->Send();
						die;
					}
				case "EXECUTE":
					if ($this->checkHash($this->config['HostKey'], $_SERVER['HTTP_X_NONCE'], $_SERVER['HTTP_X_HASH'])) {
						$this->Sync();
						die("done");
					}
				default:
					die("No Action");
			}
		}
	}

	public function proxyPostAction()
	{
		$this->getConfig();
	    $url = $_REQUEST['proxy_url'];

		//open connection
		$ch = curl_init();

		//set the url, number of POST vars, POST data
		curl_setopt($ch,CURLOPT_URL, $url);
		curl_setopt($ch,CURLOPT_POST, count($_REQUEST));
		curl_setopt($ch,CURLOPT_POSTFIELDS, http_build_query($_POST));

		$headers = array();

		foreach($this->getAllHeaders(array("X-HostKey" => $this->config['HostKey'])) as $k=>$v)
		{
			if($k != "Host")
			$headers[] = $k.": ".$v;
		}
		
		curl_setopt($ch,CURLOPT_HTTPHEADER ,$headers);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER ,true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); 
		curl_setopt($ch,CURLOPT_VERBOSE, 1);
		curl_setopt($ch,CURLOPT_HEADER, 1);

		//execute post
		$response = curl_exec($ch);

		//get response data
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$header = substr($response, 0, $header_size);
		$body = substr($response, $header_size);

		// Drop the resopnse headers -> they are probably going to try and force a redirect or something anyway //

		print_r($body);

	}

	public function proxyGetAction()
	{
	
		if(strtolower($_SERVER['REQUEST_METHOD']) == "post")
		{
			$this->proxyPostAction();
			die;
		}

		$this->getConfig();
		$url = $_REQUEST['proxy_url'];

		if(substr($url, 0, 4) !== "http")
			$url = base64_decode($url);


		$parts = parse_url($url);
		if($parts && isset($parts['query']))
		{
			parse_str($parts['query'], $res);
			$query =  http_build_query($res);

			$fragment = "";
			if(isset($parts['fragment']))
				$fragment = "#" . $parts['fragment'];

			$url = $parts['scheme'] . "://" . $parts['host'] . $parts['path'] . ($query?"?" .$query : "")  ;
		}


		//open connection
		$ch = curl_init();

		//set the url, number of POST vars, POST data
		curl_setopt($ch,CURLOPT_URL, $url);
		
		$headers = array();

		foreach($this->getAllHeaders(array("X-HostKey" => $this->config['HostKey'])) as $k=>$v)
		{
			if($k != "Host")
			$headers[] = $k.": ".$v;
		}	
/* 		
syslog(1, "HOSTKEY: " . $this->config['HostKey']);
syslog(1, "HOSTKEY: " . print_r($headers, true));
*/
		//WARNING: If you edit the post body, then don't forget to update the content length as it is being set here.
		curl_setopt($ch,CURLOPT_HTTPHEADER ,$headers);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER ,true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); 
		//curl_setopt($ch, CURLOPT_SSL_HOST, FALSE);
		curl_setopt($ch,CURLOPT_VERBOSE, 1);
		curl_setopt($ch,CURLOPT_HEADER, 1);

		//execute post
		$response = curl_exec($ch);
		
		if($response === false)
		{
			echo 'Oops, there was an error: ' . curl_error($ch);
		}

		//get response data
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$header = substr($response, 0, $header_size);
		$body = substr($response, $header_size);

		if(stripos($header, "Location") !== false)
		{
			preg_match("/Location:(.*)/i", $header, $matches);
			if($matches && isset($matches[1]))
			{
				$location = $matches[1];
				header("Location:/index.php/codisto-sync/sync/proxyGet?proxy_url=" . $matches[1]);
			}
			echo $body;
			die;

		}
		// output the headers verbatim
		foreach(explode("\r\n", $header) as $i => $h)
		{
//			if(stripos($h, "Content-Length") === false``)
				header($h);
		}

//		header("Content-Length:" . strlen($body));
//      All posts should be to the current URL only, that way we don't need to worry about Mage::BaseDir().
		echo $body;
	}

	public function checkPluginAction()
	{
		$this->getConfig();
		echo "SUCCESS";
		die;
	}

	public function testSyncAction()
	{
		$this->Sync();
		if(isset($_GET['send']))
			$this->Send();
	}

	public function configUpdateAction()
	{ //http://ec2-54-79-136-204.ap-southeast-2.compute.amazonaws.com/magento/index.php/codisto-sync/sync/configUpdate

		if (!isset($_GET['merchantid']) || !isset($_GET['hash']) || !isset($_GET['hostid'])) {
			die("FAIL - missing crendentials - " . print_r($_GET, true));
		}

		$Hash = $_GET['hash'];
		$MerchantID = (int)$_GET['merchantid'];
		$HostID = (int)$_GET['hostid'];

		$ch = curl_init();
		curl_setopt($ch,CURLOPT_URL,"https://secure.ezimerchant.com/" . $MerchantID . "/proxy/" . $HostID . "/get-merchant-details?hash=" . urlencode($Hash));
		curl_setopt($ch,CURLOPT_RETURNTRANSFER ,true);
		$result = curl_exec($ch);
		curl_close($ch);
		
		//$result = file_get_contents("https://secure.ezimerchant.com/" . $MerchantID . "/proxy/" . $HostID . "/get-merchant-details?hash=" . urlencode($Hash));

		try {
			$data = json_decode($result, true);
			if ($data['MerchantID'] == $MerchantID) {
				Mage::getModel("core/config")->saveConfig("codisto/merchantid", $data['MerchantID']);
				Mage::getModel("core/config")->saveConfig("codisto/apikey", $data['ApiKey']);
				Mage::getModel("core/config")->saveConfig("codisto/hostkey", $data['HostKey']);
				Mage::getModel("core/config")->saveConfig("codisto/hostid", $data['HostID']);
				Mage::getModel("core/config")->saveConfig("codisto/partnerid", $data['PartnerID']);
				Mage::getModel("core/config")->saveConfig("codisto/partnerkey", $data['PartnerKey']);

				//Mage::app()->cleanCache();
				Mage::app()->removeCache('config_store_data');
				Mage::app()->getCacheInstance()->cleanType('config');
				Mage::app()->getStore()->resetConfig();

				echo "SUCCESS";
			} else
				echo "FAIL - ID Mismatch. - " . $data['MerchantID'] . " : " . $MerchantID . " : " . $result;
		} catch (Exception $e) {
			print_r($e);
			echo "FAIL - exception";
		}
		die;
	}

	private function getAllHeaders($extra = false) {
		foreach ($_SERVER as $name => $value)
		{
			if (substr($name, 0, 5) == 'HTTP_')
			{
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
				$headers[$name] = $value;
			} else if ($name == "CONTENT_TYPE") {
				$headers["Content-Type"] = $value;
			} else if ($name == "CONTENT_LENGTH") {
				$headers["Content-Length"] = $value;
			}
		}
		if($extra)
			$headers = array_merge($headers, $extra);
		return $headers;
	}

	private function getConfig()
	{
		$this->config = array(
			"MerchantID" => Mage::getStoreConfig('codisto/merchantid'),
			"ApiKey" => Mage::getStoreConfig('codisto/apikey'),
			"HostKey" => Mage::getStoreConfig('codisto/hostkey'),
			"HostID" => Mage::getStoreConfig('codisto/hostid'),
			"PartnerID" => Mage::getStoreConfig("codisto/partnerid"),
			"PartnerKey" => Mage::getStoreConfig("codisto/partnerkey")
		);

		if (!$this->config['MerchantID'] || $this->config['MerchantID'] == "")
			die("Config Error - Missing MerchantID");
		if (!$this->config['ApiKey'] || $this->config['ApiKey'] == "")
			die("Config Error - Missing ApiKey");
		if (!$this->config['HostKey'] || $this->config['HostKey'] == "")
			die("Config Error - Missing HostKey");
		if (!$this->config['HostID'] || $this->config['HostID'] == "")
			die("Config Error - Missing HostID");
		if (!$this->config['PartnerID'] || $this->config['PartnerID'] == "")
			die("Config Error - Missing PartnerID");
		if (!$this->config['PartnerKey'] || $this->config['PartnerKey'] == "")
			die("Config Error - Missing PartnerKey");
	}
	public function testHashAction()
	{
		$this->getConfig();
		if($this->checkHash($this->config['HostKey'], $_SERVER['HTTP_X_NONCE'], $_SERVER['HTTP_X_HASH']))
			echo "OK";

	}
	private function checkHash($HostKey, $Nonce, $Hash)
	{
		$r = $HostKey . $Nonce;
		$base = hash("sha256", $r, true);
		$checkHash = base64_encode($base);
		if ($Hash != $checkHash)
		{
			die('Hash Mismatch Error.');
		}
		return true;
	}

	private function Send()
	{
		$syncDb = Mage::getBaseDir("var") . "/eziimport0.db";
		$f = fopen($syncDb, "rb");


		header("Cache-Control: no-cache, must-revalidate"); //HTTP 1.1
		header("Pragma: no-cache"); //HTTP 1.0
		header("Expires: Thu, 01 Jan 1970 00:00:00 GMT"); // Date in the past		
		
		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename=' . basename($syncDb));
		header('Content-Transfer-Encoding: binary');
		//header('Expires: 0');
		//header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		//header('Pragma: public');
		header('Content-Length: ' . filesize($syncDb));
//		ob_clean();
		while (!feof($f)) {
			echo fread($f, 1024);
			flush();
		}
		fclose($f);
	}

	private function Sync()
	{
		ini_set('max_execution_time', 300);
		
		// clear the database
		$syncDb = Mage::getBaseDir("var") . "/eziimport0.db";
		if (file_exists($syncDb))
			unlink($syncDb);

		//Catalog Category (Entity Type ID - 3), Catalog Product (Entity Type ID - 4), Customer (Entity Type ID - 1), Customer Address (Entity Type ID - 2), Order (Entity Type ID - 5),

		// generate the DB
		$db = new PDO("sqlite:" . $syncDb);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$db->exec("BEGIN EXCLUSIVE TRANSACTION");

		$db->query("CREATE TABLE 'Category' ('ExternalReference' int, 'ParentExternalReference' int, CategoryImage TEXT,
							'LastModified' datetime, 'Enabled' int, 'Name' string, 'Sequence' int, include_in_menu int, Level int, meta_title string)");
		$db->query("CREATE TABLE 'CategoryProduct' ('CategoryExternalReference' int, 'ProductExternalReference' int, Sequence int)");
		$db->query("CREATE TABLE 'Product' ( 'Name' string, 'Price' real, 'ListPrice' real, 'TaxID' int, 'Description' blob,
							'PlainTextDescription' string, 'Enabled' int, 'Height' int, 'HeightEntered' null, 'Width' int, 
							'Length' int, 'Weight' real, 'StockControl' int, 'DateCreated' datetime, 'DateModified' datetime, 
							'DetailedPage' int, 'TemplateFile' null, 'UnavailableMessage' string, 'Availability' string, 
							'MinBuy' int, 'LotBuy' int, 'MaxBuy' int, 'Manufacturer' string, 'ManufacturerCode' string, 'OutOfStock' string, 
							'ExternalReference' int, 'image1' string, 'ID' int, 'Code' string, 'Barcode' string, 'StockLevel' int, 
							'ProductURL' string, 'ManufacturerImage' string, 'ProductTaxClass' int,
							'ProductOrdered' int, MetaTitle TEXT, MetaDescription TEXT)");
		$db->query("CREATE TABLE ProductImage
						(
						    ProductExternalReference TEXT NOT NULL,
						    URL TEXT,
						    Tag TEXT NOT NULL DEFAULT '',
						    Sequence INTEGER NOT NULL
						)");
		$db->query("CREATE TABLE SKUImage
						(
						    SKUExternalReference TEXT NOT NULL,
						    URL TEXT,
						    Tag TEXT NOT NULL DEFAULT '',
						    Sequence INTEGER NOT NULL,
						    PRIMARY KEY ( SKUExternalReference, Tag, Sequence )
						)");
		$db->query("CREATE TABLE 'Configuration' ('configuration_id' int, 'configuration_title' blob, 'configuration_key' string,
							'configuration_value' blob, 'configuration_description' blob, 'configuration_group_id' int, 'sort_order' int,
							'last_modified' datetime, 'date_added' datetime, 'use_function' blob, 'set_function' blob)");
		$db->query("CREATE TABLE SKU
						(
						    ExternalReference TEXT,
						    Code TEXT,
						    ProductExternalReference TEXT NOT NULL,
						    StockControl TEXT NOT NULL,
						    StockLevel INTEGER,
						    Price TEXT,
							Enabled int
						)");
		$db->query("CREATE TABLE SKUMatrix
						(
						    SKUExternalReference TEXT NOT NULL,
						    Code TEXT NOT NULL,
						    OptionName TEXT NOT NULL,
						    OptionValue TEXT NOT NULL,
						    PriceModifier TEXT
						)");

		$db->query("CREATE TABLE OptionMatrix
						(
						    SKUExternalReference TEXT NOT NULL,
						    Code TEXT NOT NULL,
						    OptionName TEXT NOT NULL,
						    OptionValue TEXT NOT NULL,
						    PriceModifier TEXT
						)");

		$db->query("CREATE TABLE Log
						(
						    ID,
							Type TEXT,
							Content
						)");
		$insertlog = $db->prepare("INSERT INTO Log(ID, Type, Content) VALUES(?,?,?)");
						
		$SkuDD = $PO = $db->prepare("INSERT INTO SKU(ExternalReference, Code, ProductExternalReference, StockControl, StockLevel, Price, Enabled) VALUES(?,?,?,?,?,?,?)");
		$SKUMatrixDD = $POV = $db->prepare("INSERT INTO SKUMatrix(SKUExternalReference, Code, OptionName, OptionValue, PriceModifier) VALUES(?,?,?,?,?)");
		$insertImages = $db->prepare("INSERT INTO ProductImage(ProductExternalReference, URL, Sequence) VALUES(?,?,?)");
		$insertSKUImages = $db->prepare("INSERT INTO SkuImage(SKUExternalReference, URL, Sequence) VALUES(?,?,?)");
		
		// Configuration
		$config = array(
			'baseurl' => Mage::getBaseUrl(),
			'skinurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN),
			'mediaurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA),
			'jsurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_JS),
			'storeurl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB),
			'logourl' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN) . "frontend/" .
				Mage::getDesign()->getTheme('frontend') . "/" . Mage::getDesign()->getTheme('frontend') . "/" .
				Mage::getStoreConfig('design/header/logo_src'),
			'theme' => Mage::getDesign()->getTheme('frontend')
		);
		$insert = $db->prepare("INSERT INTO Configuration(configuration_key, configuration_value) VALUES(?,?)");

		foreach ($config as $key => $value) {
			$insert->execute(array($key, $value));
		}

		// Categories
		$collection = Mage::getModel('catalog/category')->getCollection()->addAttributeToSelect(array('name', 'image', '*', 'is_active'));
		$insertData = array('entity_id', 'image', 'parent_id', 'updated_at', 'is_active', 'name', 'position', 'include_in_menu', 'level', 'meta_title');
		foreach ($collection as $categoryData) {
			$category = $categoryData->getData();
			if ($category['level'] < 2)
				continue;
			if ($category['level'] == 2)
				$parentid = 0;
			else
				$parentid = $category['parent_id'];

			$insert = $db->prepare("INSERT INTO Category(ExternalReference, CategoryImage, ParentExternalReference,
															LastModified, Enabled, Name, Sequence, include_in_menu, Level, meta_title)
										VALUES(?,?,?,?,?,?,?,?,?,?)");
			$i = array();
			foreach ($insertData as $key) {
				if ($key == "parent_id")
					$i[] = $parentid;
				else
					$i[] = (isset($category[$key])) ? $category[$key] : "";
			}
			$insert->execute($i);
		}
		unset($category);
		unset($categoryData);
		unset($insert);
		unset($insertData);
		
		// Product Mapping
		$insertData = array(
			//magento=>import
			'name' => 'Name',
			//'price' => 'Price',
			'price' => 'ListPrice',
			//'taxid' => 'TaxID', //'tax_class_id'
			'description' => 'Description',
			'' => 'PlainTextDescription',
			'status' => 'Enabled',
			'' => 'Height',
			'' => 'Width',
			'' => 'Length',
			'weight' => 'Weight',
			'created_at' => 'DateCreated',
			'updated_at' => 'DateModified',
			'' => 'Deleted',
			'' => 'DateDeleted',
			'' => 'DetailedPage',
			'' => 'TemplateFile',
			'' => 'UnavailableMessage',
			'is_salable' => 'Availability',
			'' => 'MinBuy',
			'' => 'LotBuy',
			'' => 'MaxBuy',
			'' => 'Manufacturer',
			'manufacturer' => 'ManufacturerCode',
			'' => 'OutOfStock',
			'entity_id' => 'ExternalReference',
			'image' => 'image1',
			'' => 'ID',
			'sku' => 'Code',
			'' => 'Barcode',
			'' => 'EnabledActual',
			'' => 'StockLevel',
			'' => 'StockControl',
			'' => 'ProductURL',
			'' => 'ManufacturerImage',
			'' => 'ProductTaxClass',
			'' => 'ProductOrdered',
			'meta_title' => 'MetaTitle',
			'meta_description' => 'MetaDescription',
			//            '' => 'ProductID'
		);

		$store = Mage::app()->getStore('default');
		
		// Products: CONFIGURABLE
		$configurableCollection = Mage::getModel('catalog/product')->getCollection()
			->addAttributeToSelect(array('*', 'name', 'image', 'description', 'product_url', 'price', 'special_price', 'main'))
			->addAttributeToFilter('type_id', array('eq' => 'configurable'));

		$insertedProducts = array();

		foreach ($configurableCollection as $productConfigurableData) {
			$product = $productConfigurableData->getData();
			$productloader = Mage::getModel('catalog/product')->load($product['entity_id']);

			$taxCalculation = Mage::getModel('tax/calculation');
			$request = $taxCalculation->getRateRequest(null, null, null, $store);
			$taxClassId = $productloader->getTaxClassId();
			$percent = $taxCalculation->getRate($request->setProductClassId($taxClassId));
			if($percent === 0 || !$percent)
				$percent = 10;			
			
			$productprice = $productloader->getFinalPrice();
			
			$insertlog->execute(array($product['entity_id'], 'product', print_r($product, true)));
			
			if(!$product['description']) {
				$_product = Mage::getModel('catalog/product')->load($product['entity_id']);
				$product['description'] = $_product->description;
			}

			// Extract the fields from Product
			$fields = array();
			foreach ($insertData as $magentoKey => $eziKey) {
				$fields[$eziKey] = isset($product[$magentoKey]) ? $product[$magentoKey] : "";
			}
			/*$_product = Mage::getModel('catalog/product')->load($productId);
			$_priceIncludingTax = $this->helper('tax')
			->getPrice($_product, $_product->getFinalPrice());*/

			// Special fields
			$stockData = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product['entity_id'])->getData();
			
//syslog(1, print_r($stockData, true));
			
			$configurable_product = Mage::getModel('catalog/product')->load($product['entity_id']);
			
//syslog(1, "configurable_product->getFinalPrice: " . print_r($configurable_product->getFinalPrice(), true));	
//syslog(1, "product['price']: " . $product['price']);

			$fields['MinBuy'] = (int)$stockData['min_sale_qty'];
			$fields['MaxBuy'] = (int)$stockData['max_sale_qty'];
			$fields['StockControl'] = $stockData['manage_stock']; //TODO: check whether we need to normalise the boolean value
			$fields['StockLevel'] = (int)$stockData["qty"];
			if($productConfigurableData->getResource()->getAttribute('manufacturer')) {
				$fields['Manufacturer'] = $productConfigurableData->getAttributeText('manufacturer');
			} else {
				$fields['Manufacturer'] = '';//$productConfigurableData->getAttributeText('manufacturer');
			}
			$fields['Price'] = isset($productprice) ? $productprice / (1+($percent/100))  : "";
			$fields['TaxID'] = 1;

			if ($product['status'] != 1)
				$fields['Enabled'] = 0;
			else
				$fields['Enabled'] = -1;

			$query = array();
			$query[] = "INSERT INTO Product(";
			$query[] = implode(array_keys($fields), ",");
			$query[] = ") VALUES(";
			$data = array();
			foreach (array_keys($fields) as $key) {
				$data[":" . $key] = $fields[$key];
			}
			$query[] = implode(array_keys($data), ",");
			$query[] = ")";

			try {
				$insert = $db->prepare(implode($query));
				$insert->execute($data);
			} catch (Exception $e) {
				print_r($e);
			}

			// Add the product id to the list of already processed products
			$insertedProducts[] = $product['entity_id'];
			$parentProductExternalReference =  $product['entity_id'];
			// CategoryProductMM
			$insertCategory = $db->prepare("INSERT INTO CategoryProduct('ProductExternalReference', 'CategoryExternalReference', Sequence) VALUES(?,?,?)");
			$product = Mage::getModel('catalog/product')->load($product['entity_id']);
			
			$categoryIds = $product->getCategoryIds();
			foreach ($categoryIds as $categoryId) {
				$insertCategory->execute(array($product['entity_id'], $categoryId, 0));
			}

			// ProductImages
			$productConfigurableData->load('media_gallery');
			foreach ($productConfigurableData->getMediaGalleryImages() as $image) {
				if ($image->getDisabled() != 0) continue;
				$insertImages->execute(array($product['entity_id'], $image->getUrl(), $image->getPosition()));
			}
			
			//the configurable product id
			$productId = $product['entity_id']; 
			//load the product - this may not be needed if you get the product from a collection with the prices loaded.
			$productdata = Mage::getModel('catalog/product')->load($productId); 
			//get all configurable attributes
			$attributes = $productdata->getTypeInstance(true)->getConfigurableAttributes($product);
			//array to keep the price differences for each attribute value
			$pricesByAttributeValues = array();
			//base price of the configurable product 
			$basePrice = $productdata->getFinalPrice();
			//loop through the attributes and get the price adjustments specified in the configurable product admin page
			foreach ($attributes as $attribute) {
			
				$prices = $attribute->getPrices();

				foreach ($prices as $price){
					if ($price['is_percent']){ //if the price is specified in percents
						$pricesByAttributeValues[$price['value_index']] = (float)$price['pricing_value'] * $basePrice / 100;
					}
					else { //if the price is absolute value
						$pricesByAttributeValues[$price['value_index']] = (float)$price['pricing_value'];
					}
				}
			}

//syslog(1, "pricesByAttributeValues: " . print_r($pricesByAttributeValues, true));

			// SKUs
			//$childProducts = Mage::getModel('catalog/product_type_configurable')->getUsedProducts(null,$productConfigurableData);
			$childProducts =  Mage::getModel('catalog/product_type_configurable')->setProduct($productConfigurableData)
				->getUsedProductCollection()->addAttributeToSelect('*')->addFilterByRequiredOptions();

			$configurableAttributes = $product->getTypeInstance(true)->getConfigurableAttributes($productConfigurableData);
			foreach($childProducts as $child)
			{
				$product = $child->getData();
				$stockData = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product['entity_id'])->getData();
				$pricemodifier = 0;
				
				//get all simple products
				$simple = $productdata->getTypeInstance()->getUsedProducts();
				//loop through the products
				foreach ($simple as $sProduct){
//syslog(1, '$sProduct: ' . print_r($sProduct['entity_id'], true));				
					$totalPrice = $basePrice;
					//loop through the configurable attributes
					foreach ($attributes as $attribute){
						//get the value for a specific attribute for a simple product
						$value = $sProduct->getData($attribute->getProductAttribute()->getAttributeCode());
						//add the price adjustment to the total price of the simple product
						if (isset($pricesByAttributeValues[$value])){
							$totalPrice += $pricesByAttributeValues[$value];
						}
					}
					//in $totalPrice you should have now the price of the simple product
					//do what you want/need with it
					if($sProduct['entity_id'] === $product['entity_id']) {
						$productTotalPrice = $totalPrice / (1+($percent/100));
//syslog(1, "productTotalPrice: " . $productTotalPrice);
						$pricemodifier = $pricesByAttributeValues[$value];
//syslog(1, "pricemodifier: " . $pricemodifier);
					}
					
				}
				
				//SKU(ExternalReference, Code, ProductExternalReference, StockControl, StockLevel, Price)
				$SkuDD->execute(array($product['entity_id'], $product['sku'], $parentProductExternalReference, $stockData['manage_stock'],
					(int)$stockData["qty"], isset($productTotalPrice) ? $productTotalPrice : "", $product['status'] != 1 ? 0 : -1));

				// Add the product id to the list of already processed products
				$insertedProducts[] = $product['entity_id'];

				// SKU Matrix
				$candidates = array();
				foreach($configurableAttributes as $attribute)
				{
				
					if($child->getResource()->getAttribute($productAttribute->getAttributeCode())) {
						$attrsku = $child->getAttributeText($productAttribute->getAttributeCode());
					} else {
						$attrsku = '';					
					}
								
					$productAttribute = $attribute->getProductAttribute();
//					$productAttributeId = $productAttribute->getId();
					$SKUMatrixDD->execute(array($child->getId(), $product['sku'], $productAttribute->getAttributeCode(), 
					//$child->getAttributeText($productAttribute->getAttributeCode()), 
					$attrsku,
					$pricemodifier ));
				}

				//TODO : Delete the sku options when there is only one choice.

				// SKUImage
				$child->load('media_gallery');
				foreach ($child->getMediaGalleryImages() as $image) {
					if ($image->getDisabled() != 0) continue;

					$insertSKUImages->execute(array($product['entity_id'], $image->getUrl(), $image->getPosition()));
				}

			}
		}


		// Products: SIMPLE
		$collection = Mage::getModel('catalog/product')->getCollection()
			->addAttributeToSelect(array('*', 'name', 'image', 'description', 'product_url', 'price', 'special_price', 'main'))
			->addAttributeToFilter('type_id', array('eq' => 'simple'));

		foreach ($collection as $productData) {
			$product = $productData->getData();
			
			$taxCalculation = Mage::getModel('tax/calculation');
			$request = $taxCalculation->getRateRequest(null, null, null, $store);
			$taxClassId = $productData->getTaxClassId();
			$percent = $taxCalculation->getRate($request->setProductClassId($taxClassId));
			if($percent === 0 || !$percent)
				$percent = 10;

			if(!$product['description']) {
				$_product = Mage::getModel('catalog/product')->load($product['entity_id']);
				$product['description'] = $_product->description;
			}
				
			$insertlog->execute(array($product['entity_id'], 'product', print_r($product, true)));
			$insertlog->execute(array($product['entity_id'], 'attr', $productData->getDescription()));
			
			$productloader = Mage::getModel('catalog/product')->load($product['entity_id']);

			$productprice = $productloader->getFinalPrice();

			if(in_array($product['entity_id'], $insertedProducts))
					continue;

			// Extract the fields from Product
			$fields = array();
			foreach ($insertData as $magentoKey => $eziKey) {
				$fields[$eziKey] = isset($product[$magentoKey]) ? $product[$magentoKey] : "";
			}

			// StockData example
//				Array
//				(
//					[item_id] => 1
//				    [product_id] => 1
//				    [stock_id] => 1
//				    [qty] => 10.0000
//				    [min_qty] => 0.0000
//				    [use_config_min_qty] => 1
//				    [is_qty_decimal] => 0
//				    [backorders] => 0
//				    [use_config_backorders] => 1
//				    [min_sale_qty] => 1.0000
//				    [use_config_min_sale_qty] => 1
//				    [max_sale_qty] => 0.0000
//				    [use_config_max_sale_qty] => 1
//				    [is_in_stock] => 1
//				    [low_stock_date] =>
//				    [notify_stock_qty] =>
//				    [use_config_notify_stock_qty] => 1
//				    [manage_stock] => 0
//				    [use_config_manage_stock] => 1
//				    [stock_status_changed_auto] => 0
//				    [use_config_qty_increments] => 1
//				    [qty_increments] => 0.0000
//				    [use_config_enable_qty_inc] => 1
//				    [enable_qty_increments] => 0
//				    [is_decimal_divided] => 0
//				    [type_id] => simple
//				    [stock_status_changed_automatically] => 0
//				    [use_config_enable_qty_increments] => 1
//				)

			// Special fields
			$stockData = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product['entity_id'])->getData();
			$fields['MinBuy'] = (int)$stockData['min_sale_qty'];
			$fields['MaxBuy'] = (int)$stockData['max_sale_qty'];
			$fields['StockControl'] = $stockData['manage_stock']; //TODO: check whether we need to normalise the boolean value
			$fields['StockLevel'] = (int)$stockData["qty"];
			//$product->getResource()->getAttribute('manufacturer')
			if(false) {
				$fields['Manufacturer'] = $productConfigurableData->getAttributeText('manufacturer');
			} else {
				$fields['Manufacturer'] = '';//$productConfigurableData->getAttributeText('manufacturer');
			}
			$fields['Price'] = isset($productprice) ? $productprice / (1+($percent/100)) : "";
			$fields['TaxID'] = 1;

//              $fields['image1']
//              Possible for out of stock?
//              $fields['OutOfStock'] = $stockItem->getIsInStock()?0:1;

			if ($product['status'] != 1)
				$fields['Enabled'] = 0;
			else
				$fields['Enabled'] = -1;

			$query = array();
			$query[] = "INSERT INTO Product(";
			$query[] = implode(array_keys($fields), ",");
			$query[] = ") VALUES(";
			$data = array();
			foreach (array_keys($fields) as $key) {
				$data[":" . $key] = $fields[$key];
			}
			$query[] = implode(array_keys($data), ",");
			$query[] = ")";


			try {
				$insert = $db->prepare(implode($query));
				$insert->execute($data);
			} catch (Exception $e) {
				print_r($e);
			}

			// CategoryProductMM
			$insertCategory = $db->prepare("INSERT INTO CategoryProduct('ProductExternalReference', 'CategoryExternalReference', Sequence) VALUES(?,?,?)");
			$product = Mage::getModel('catalog/product')->load($product['entity_id']);
			$categoryIds = $product->getCategoryIds();
			foreach ($categoryIds as $categoryId) {
				$insertCategory->execute(array($product['entity_id'], $categoryId, 0));
			}

			// ProductImages
			$productData->load('media_gallery');
			$insertImages = $db->prepare("INSERT INTO ProductImage(ProductExternalReference, URL, Sequence) VALUES(?,?,?)");
			foreach ($productData->getMediaGalleryImages() as $image) {
				if ($image->getDisabled() != 0) continue;
				$insertImages->execute(array($product['entity_id'], $image->getUrl(), $image->getPosition()));
			}

			// ProductOptions
			$options = $product->getOptions();
			$optionrows = array();
			$optionnames = array();
			
			if (count($options) > 0) {

				foreach ($options as $option) {
					$o = $option->getData();

					switch (strtolower($o['type'])) {
						case "textarea":
						case "field":
							$type = "text";
							break;
						case "drop_down":
						case "radio":
						default:
							$type = "select";
					}

					foreach ($option->getValues() as $optionValue) {
						$ov = $optionValue->getData();

//                      There is a thing inside the magento control panel that allows you to select fixed or percentage. not really sure how it works at this stage.
//						$price = 0;
//						if ($ov['price_type'] == 'percent')
//							$price = ($ov['price'] / 100) * $fields['Price'];
//						else
//							$price = $fields['Price'] + ((float)$ov['price']);
//													$ov['sort_order']
//                      print_r($ov);
//                      print_r($o);

//syslog(1, 'productprice: ' . print_r($productprice, true));
//syslog(1, 'optionvalue: ' . print_r($ov['price'], true));

						$price = ($productprice + (float)$ov['price']) / (1+($percent/100));
						try {
						
							//Cross Multiply
							
							$SKUID =  implode("-", array($product['entity_id'], $o['option_id'], $ov['option_type_id'])); //$ov['sku']; // $product['entity_id'] ."-" .
							
							$row = array();
							
							$row[] = array('skuid' => $SKUID, 'code' => $ov['sku'], 'optionname' => $o['title'], 'optionvalue' => $ov['title'], 'optionprice' => $ov['price']);
							
							$optionrows[] = $row;
							
							//$PO->execute(array($SKUID, $ov['sku'], $product['entity_id'], -1, 1, $price, -1));
							//$POV->execute(array($SKUID, $ov['sku'], $o['title'], $ov['title'], $ov['price']));
						
						
						} catch (Exception $e) {
							print_r($e);
						}
					}
					
					$optionnames[] = $o['title'];
					
				}
				
				//$OptionMatrix = $db->prepare("INSERT INTO OptionMatrix(SKUExternalReference, Code, OptionName, OptionValue, PriceModifier) VALUES(?,?,?,?,?)");
				
				$seen = array();
				if(count($optionnames) > 0) {
//syslog(1, '::::optionrows::::'. $product['name']);
					foreach($optionnames as $optionname1) {
//syslog(1, print_r($optionname1, true));
						foreach($optionrows as $optionrow) {
						
							$optionname = $optionrow[0]['optionname'];

							if($optionname != $optionname1)
								continue;
								
							if(in_array($optionname, $seen))
								continue;
										
							$value = $optionrow[0]['optionvalue'];
							$code = $optionrow[0]['code'];
							$optionskuid = $optionrow[0]['skuid'];
							$price = $optionrow[0]['optionprice'];
//syslog(1, print_r($optionname . ' : ' . $value . ' : ' . $code . ' ; ' . $optionskuid, true));
								foreach($optionrows as $optionrow2) {
									if($optionname != $optionrow2[0]['optionname'] && $value != $optionrow2[0]['optionvalue']) {

//syslog(1, print_r($optionrow2[0]['optionname'] . ' : ' . $optionrow2[0]['optionvalue'] . ' : ' . $optionrow2[0]['code'] . ' ; ' . $optionrow2[0]['skuid'], true));
//syslog(1, print_r($optionname . ':::' . $optionrow2[0]['optionname'], true));
//syslog(1, print_r($optionrow2[0]['optionvalue'] . '-' . $optionrow[0]['optionvalue'], true));
										
										$coptionname = $optionname . '-' . $optionrow2[0]['optionname'];
										$cvalue =  $value . '-' . $optionrow2[0]['optionvalue'];
										$ccode =  $code. '-' . $optionrow2[0]['code'];
										//if($ccode === '-')
										//	$ccode = null;
										$coptionskuid =  $optionskuid . '-' . $optionrow2[0]['skuid'];
										$cprice =  $productprice + $price + $optionrow2[0]['optionprice']; // (1+($percent/100)
									
										$POV->execute(array($coptionskuid, $ccode, $optionrow2[0]['optionname'], $optionrow2[0]['optionvalue'], $optionrow2[0]['optionprice']));
										$POV->execute(array($coptionskuid, $ccode, $optionname, $value, $price));
										$PO->execute(array($coptionskuid, $ccode, $product['entity_id'], -1, 1, $cprice, -1));
										
										$seen[] = $optionrow2[0]['optionname'];
									}
								}
							
							//$POV->execute(array($coptionskuid, $code, $optionname, $value, $price));
						}
					}
				}
			}
		}


		$db->exec("COMMIT TRANSACTION");
	}
}