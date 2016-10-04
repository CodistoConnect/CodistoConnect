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
 * @package	 Codisto_Sync
 * @copyright   Copyright (c) 2015 On Technology Pty. Ltd. (http://codisto.com/)
 * @license	 http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Codisto_Sync_IndexController extends Mage_Core_Controller_Front_Action
{
	public function calcAction()
	{
		set_time_limit(0);
		ignore_user_abort(false);
		@ini_set('display_errors', 1);
		@ini_set('display_startup_errors', 1);
		@error_reporting(E_ALL);

		$output = '';

		try
		{
			$request = $this->getRequest();
			$response = $this->getResponse();

			$storeId = $request->getQuery('storeid') == null ? 0 : (int)$request->getQuery('storeid');

			if($storeId == 0)
			{
				// jump the storeid to first non admin store
				$stores = Mage::getModel('core/store')->getCollection()
											->addFieldToFilter('is_active', array('neq' => 0))
											->addFieldToFilter('store_id', array('gt' => 0))
											->setOrder('store_id', 'ASC');

				$firstStore = $stores->getFirstItem();
				if(is_object($firstStore) && $firstStore->getId())
					$storeId = $firstStore->getId();
			}

			$store = Mage::app()->getStore($storeId);

			$currencyCode = $request->getPost('CURRENCY');
			if(!$currencyCode)
				$currencyCode = $store->getCurrentCurrencyCode();

			$place = $request->getPost('PLACE');
			if(!$place)
				$place = '';
			$postalcode = $request->getPost('POSTALCODE');
			$division = $request->getPost('DIVISION');
			$countrycode = $request->getPost('COUNTRYCODE');
			$regionid = null;
			$regioncode = null;

			if($countrycode == 'AU')
			{
				$pc = $postalcode{0};

				if ($pc == 2 || $pc == 1) {
					$regiontext = 'NSW';
				} else if ($pc == 3 || $pc == 8) {
					$regiontext = 'VIC';
				} else if ($pc == 4) {
					$regiontext = 'QLD';
				} else if ($pc == 5) {
					$regiontext = 'SA';
				} else if ($pc == 6) {
					$regiontext = 'WA';
				} else if ($pc == 7) {
					$regiontext = 'TAS';
				}

				$pc3 = $postalcode{0} . $postalcode{1};
				if ($pc3 == '08' || $pc3 == '09') {
					$regiontext = 'NT';
				}

				if ($postalcode == '0872') {
					$regiontext = 'SA';
				} else if ($postalcode == '2611' || $postalcode == '3500' || $postalcode == '3585' || $postalcode == '3586' || $postalcode == '3644' || $postalcode == '3707') {
					$regiontext = 'NSW';
				} else if ($postalcode == '2620') {
					$regiontext = 'ACT';
				}

				if (intval($postalcode) >= 2600 && intval($postalcode) <= 2618) {
					$regiontext = 'ACT';
				}

				$region = Mage::getModel('directory/region')->loadByCode($regiontext, $countrycode);
				if($region)
				{
					$regionid = $region->getId();
					$regioncode = $region->getCode();
				}
			}
			else
			{
				$region = Mage::getModel('directory/region')->loadByName($division, $countrycode);
				if($region)
				{
					$regionid = $region->getId();
					$regioncode = $region->getCode();
				}
			}

			$total = 0;
			$itemqty = 0;
			$totalweight = 0;

			$quote = Mage::getModel('sales/quote');
			$quote->setIsSuperMode(true);

			for($inputidx = 0; ; $inputidx++)
			{
				if(!$request->getPost('PRODUCTCODE('.$inputidx.')'))
					break;

				$productid = (int)$request->getPost('PRODUCTID('.$inputidx.')');
				if(!$productid)
				{
					$productcode = $request->getPost('PRODUCTCODE('.$inputidx.')');
					$productid = Mage::getModel('catalog/product')->getIdBySku($productcode);
				}
				else
				{
					$sku = Mage::getResourceSingleton('catalog/product')->getProductsSku(array($productid));
					if(empty($sku))
					{
						$productcode = $request->getPost('PRODUCTCODE('.$inputidx.')');
						$productid = Mage::getModel('catalog/product')->getIdBySku($productcode);
					}
				}

				$productqty = $request->getPost('PRODUCTQUANTITY('.$inputidx.')');
				if(!$productqty && $productqty !=0)
					$productqty = 1;

				$productprice = floatval($request->getPost('PRODUCTPRICE('.$inputidx.')'));
				$productpriceincltax = floatval($request->getPost('PRODUCTPRICEINCTAX('.$inputidx.')'));
				$producttax = floatval($request->getPost('PRODUCTTAX('.$inputidx.')'));

				if($productid)
				{
					$product = Mage::getModel('catalog/product')->load($productid);

					if($product)
					{
						$product->setIsSuperMode(true);

						$taxpercent = $productprice == 0 ? 0 : round($productpriceincltax / $productprice - 1.0, 2) * 100;

						$item = Mage::getModel('sales/quote_item');
						$item->setStoreId($store->getId());
						$item->setQuote($quote);

						$item->setData('product', $product);
						$item->setProductId($productid);
						$item->setProductType('simple');
						$item->setIsRecurring(false);
						$item->setTaxClassId($product->getTaxClassId());
						$item->setBaseCost($product->getCost());
						$item->setSku($product->getSku());
						$item->setName($product->getName());
						$item->setIsVirtual(0);
						$item->setIsQtyDecimal(0);
						$item->setNoDiscount(true);
						$item->setWeight($product->getWeight());
						$item->setData('qty', $productqty);
						$item->setPrice($productprice);
						$item->setBasePrice($productprice);
						$item->setCustomPrice($productprice);
						$item->setDiscountPercent(0);
						$item->setDiscountAmount(0);
						$item->setBaseDiscountAmount(0);
						$item->setTaxPercent($taxpercent);
						$item->setTaxAmount($producttax * $productqty);
						$item->setBaseTaxAmount($producttax * $productqty);
						$item->setRowTotal($productprice * $productqty);
						$item->setBaseRowTotal($productprice * $productqty);
						$item->setRowTotalWithDiscount($productprice * $productqty);
						$item->setRowWeight($product->getWeight() * $productqty);
						$item->setOriginalCustomPrice($productprice);
						$item->setPriceInclTax($productpriceincltax);
						$item->setBasePriceInclTax($productpriceincltax);
						$item->setRowTotalInclTax($productpriceincltax * $productqty);
						$item->setBaseRowTotalInclTax($productpriceincltax * $productqty);
						$item->setWeeeTaxApplied(serialize(array()));

						$total += $productpriceincltax * $productqty;
						$itemqty += $productqty;
						$totalweight += $product->getWeight() * $productqty;

						$quote->getItemsCollection()->addItem($item);
					}
				}
			}

			$quote->save();

			$checkoutSession = Mage::getSingleton('checkout/session');
			$checkoutSession->replaceQuote($quote);
			$checkoutSession->setData('destination_type', 'residence');

			$currency = Mage::getModel('directory/currency')->load($currencyCode);

			$shippingRequest = Mage::getModel('shipping/rate_request');
			$shippingRequest->setAllItems($quote->getAllItems());
			$shippingRequest->setDestCountryId($countrycode);
			if($regionid)
				$shippingRequest->setDestRegionId($regionid);
			if($regioncode)
				$shippingRequest->setDestRegionCode($regioncode);
			if($place)
				$shippingRequest->setDestCity($place);
			$shippingRequest->setDestPostcode($postalcode);
			$shippingRequest->setPackageValue($total);
			$shippingRequest->setPackageValueWithDiscount($total);
			$shippingRequest->setPackageWeight($totalweight);
			$shippingRequest->setPackageQty($itemqty);
			$shippingRequest->setPackagePhysicalValue($total);
			$shippingRequest->setFreeMethodWeight($totalweight);
			$shippingRequest->setStoreId($store->getId());
			$shippingRequest->setWebsiteId($store->getWebsiteId());
			$shippingRequest->setFreeShipping(0);
			$shippingRequest->setBaseCurrency($currency);
			$shippingRequest->setPackageCurrency($currency);
			$shippingRequest->setBaseSubtotalInclTax($total);

			$shippingResult = Mage::getModel('shipping/shipping')->collectRates($shippingRequest)->getResult();

			$shippingRates = $shippingResult->getAllRates();

			$outputidx = 0;
			foreach($shippingRates as $shippingRate)
			{
				if($shippingRate instanceof Mage_Shipping_Model_Rate_Result_Method)
				{
					$isPickup = $shippingRate->getPrice() == 0 &&
								(preg_match('/(?:^|\W|_)pick\s*up(?:\W|_|$)/i', strval($shippingRate->getMethod())) ||
									preg_match('/(?:^|\W|_)pick\s*up(?:\W|_|$)/i', strval($shippingRate->getCarrierTitle())) ||
									preg_match('/(?:^|\W|_)pick\s*up(?:\W|_|$)/i', strval($shippingRate->getMethodTitle())));

					if(!$isPickup)
					{
						$output .= 'FREIGHTNAME('.$outputidx.')='.rawurlencode($shippingRate->getMethodTitle()).'&FREIGHTCHARGEINCTAX('.$outputidx.')='.$shippingRate->getPrice().'&';
						$outputidx++;
					}
				}
			}

			try
			{
				$quote
					->setIsActive(false)
					->delete();
			}
			catch(Exception $e)
			{

			}

		}
		catch(Exception $e)
		{

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
		$response->setBody($output);
	}

	public function indexAction()
	{
		set_time_limit(0);
		ignore_user_abort(false);
		@ini_set('display_errors', 1);
		@ini_set('display_startup_errors', 1);
		@error_reporting(E_ALL);

		$request = $this->getRequest();
		$response = $this->getResponse();
		$server = $request->getServer();
		$method = isset($server['REQUEST_METHOD']) ? $server['REQUEST_METHOD'] : 'GET';
		$contenttype = isset($server['CONTENT_TYPE']) ? $server['CONTENT_TYPE'] : '';

		if($method == 'POST')
		{
			$helper = Mage::helper('codistosync');

			if($contenttype == 'text/xml')
			{
				$xml = simplexml_load_string(file_get_contents('php://input'));

				$ordercontent = $xml->entry->content->children('http://api.codisto.com/schemas/2009/');

				$storeId = @count($ordercontent->storeid) ? (int)$ordercontent->storeid : 0;

				if(!$helper->getConfig($storeId))
				{
					$response->clearAllHeaders();
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

				if($helper->checkRequestHash(Mage::getStoreConfig('codisto/hostkey', $storeId), $server))
				{
					$productsToReindex = array();
					$ordersProcessed = array();
					$invoicesProcessed = array();

					$connection = Mage::getSingleton('core/resource')->getConnection('core_write');

					try
					{
						$connection->addColumn(
								Mage::getConfig()->getTablePrefix() . 'sales_flat_order',
								'codisto_orderid',
								'varchar(10)'
							);
					}
					catch(Exception $e)
					{

					}

					try
					{
						$connection->addColumn(
								Mage::getConfig()->getTablePrefix() . 'sales_flat_order',
								'codisto_merchantid',
								'varchar(10)'
							);
					}
					catch(Exception $e)
					{

					}

					if($storeId == 0)
					{
						// jump the storeid to first non admin store
						$stores = Mage::getModel('core/store')->getCollection()
													->addFieldToFilter('is_active', array('neq' => 0))
													->addFieldToFilter('store_id', array('gt' => 0))
													->setOrder('store_id', 'ASC');

						$stores->setPageSize(1)->setCurPage(1);
						$firstStore = $stores->getFirstItem();
						if(is_object($firstStore) && $firstStore->getId())
							$storeId = $firstStore->getId();
					}

					$store = Mage::app()->getStore($storeId);

					Mage::app()->setCurrentStore($store);

					$quote = null;

					for($Retry = 0; ; $Retry++)
					{
						$productsToReindex = array();

						try
						{
							$quote = Mage::getModel('sales/quote');

							$this->ProcessQuote($quote, $xml, $store, $request);
						}
						catch(Exception $e)
						{
							$response = $this->getResponse();

							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(200);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(200);
							$response->setRawHeader('HTTP/1.0 200 OK');
							$response->setRawHeader('Status: 200 OK');
							$response->setHeader('Content-Type', 'application/json');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody(Zend_Json::encode(array( 'ack' => 'failed', 'code' => $e->getCode(), 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString())));
							break;
						}

						$connection->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
						$connection->beginTransaction();

						try
						{

							$order = Mage::getModel('sales/order')
							->getCollection()
							->addFieldToFilter('codisto_orderid', $ordercontent->orderid)
							->addFieldToFilter(
							    array('codisto_merchantid', 'codisto_merchantid'),
							    array(
							        array('in'=> array($ordercontent->merchantid, '')),
							        array('null'=> true)
							    )
							)
							->getFirstItem();

							if($order && $order->getId())
							{
								$this->ProcessOrderSync($quote, $order, $xml, $productsToReindex, $ordersProcessed, $invoicesProcessed, $store, $request);
							}
							else
							{
								$this->ProcessOrderCreate($quote, $xml, $productsToReindex, $ordersProcessed, $invoicesProcessed, $store, $request);
							}

							$connection->commit();
							break;
						}
						catch(Exception $e)
						{

							if($Retry < 5)
							{
								if($e->getCode() == 40001)
								{
									$connection->rollback();
									sleep($Retry * 10);
									continue;
								}
							}

							$response = $this->getResponse();

							$response->clearAllHeaders();
							//@codingStandardsIgnoreStart
							if(function_exists('http_response_code'))
								http_response_code(200);
							//@codingStandardsIgnoreEnd
							$response->setHttpResponseCode(200);
							$response->setRawHeader('HTTP/1.0 200 OK');
							$response->setRawHeader('Status: 200 OK');
							$response->setHeader('Content-Type', 'application/json');
							$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
							$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
							$response->setHeader('Pragma', 'no-cache', true);
							$response->setBody(Zend_Json::encode(array( 'ack' => 'failed', 'code' => $e->getCode(), 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString())));

							$connection->rollback();
							break;
						}
					}

					try
					{
						if(!empty($ordersProcessed))
						{
							Mage::getResourceModel('sales/order')->updateGridRecords($ordersProcessed);
						}
					}
					catch (Exception $e)
					{

					}

					try
					{
						if(!empty($invoicesProcessed))
						{
							Mage::getResourceModel('sales/order_invoice')->updateGridRecords($invoicesProcessed);
						}
					}
					catch (Exception $e)
					{

					}

					try
					{

						if(!empty($productsToReindex))
						{
							Mage::getResourceSingleton('cataloginventory/indexer_stock')->reindexProducts($productsToReindex);
							Mage::getResourceSingleton('catalog/product_indexer_price')->reindexProductIds($productsToReindex);
						}

					}
					catch (Exception $e)
					{

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
					return;
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
				$response->setRawHeader('HTTP/1.0 400 Invalid Content Type');
				$response->setRawHeader('Status: 400 Invalid Content Type');
				$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
				$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
				$response->setHeader('Pragma', 'no-cache', true);
				$response->setBody('Invalid Content Type');
				return;
			}
		}
		else
		{
			include_once Mage::getBaseDir() . '/errors/404.php';
		}
	}

	private function ProcessOrderCreate($quote, $xml, &$productsToReindex, &$orderids, &$invoiceids, $store, $request)
	{

		$ordercontent = $xml->entry->content->children('http://api.codisto.com/schemas/2009/');

		$paypaltransactionid = $ordercontent->orderpayments[0]->orderpayment->transactionid;
		$ordernumberformat = (string)$ordercontent->ordernumberformat;

		$ordertotal = floatval($ordercontent->ordertotal[0]);
		$ordersubtotal = floatval($ordercontent->ordersubtotal[0]);
		$ordertaxtotal = floatval($ordercontent->ordertaxtotal[0]);

		$ordersubtotal = $store->roundPrice($ordersubtotal);
		$ordersubtotalincltax = $store->roundPrice($ordersubtotal + $ordertaxtotal);
		$ordertotal = $store->roundPrice($ordertotal);

		$ebaysalesrecordnumber = (string)$ordercontent->ebaysalesrecordnumber;
		if(!$ebaysalesrecordnumber)
			$ebaysalesrecordnumber = '';

		$ebaytransactionid = (string)$ordercontent->ebaytransactionid;

		$ebayusername = (string)$ordercontent->ebayusername;
		if(!$ebayusername)
			$ebayusername = '';

		$quoteConverter =  Mage::getModel('sales/convert_quote');

		$quote->reserveOrderId();
		$order = $quoteConverter->addressToOrder($quote->getShippingAddress());
		$order->setBillingAddress($quoteConverter->addressToOrderAddress($quote->getBillingAddress()));
		$order->setShippingAddress($quoteConverter->addressToOrderAddress($quote->getShippingAddress()));
		$order->setPayment($quoteConverter->paymentToOrderPayment($quote->getPayment()));
		$order->setCustomer($quote->getCustomer());
		$order->setCodistoOrderid((string)$ordercontent->orderid);
		$order->setCodistoMerchantid((string)$ordercontent->merchantid);

		if(preg_match('/\{ordernumber\}|\{ebaysalesrecordnumber\}|\{ebaytransactionid\}/', $ordernumberformat))
		{
			$incrementId = preg_replace('/\{ordernumber\}/', (string)$order->getIncrementId(), $ordernumberformat);
			$incrementId = preg_replace('/\{ebaysalesrecordnumber\}/', $ebaysalesrecordnumber, $incrementId);
			$incrementId = preg_replace('/\{ebaytransactionid\}/', $ebaytransactionid, $incrementId);
			$order->setIncrementId($incrementId);
		}
		else
		{
			$incrementId = $ordernumberformat.''.(string)$order->getIncrementId();
			$order->setIncrementId($incrementId);
		}

		$weight_total = 0;

		$quoteItems = $quote->getItemsCollection()->getItems();
		$quoteIdx = 0;

		foreach($ordercontent->orderlines->orderline as $orderline)
		{
			if($orderline->productcode[0] != 'FREIGHT')
			{
				$adjustStock = true;

				$product = null;

				$productcode = $orderline->productcode[0];
				if($productcode == null)
					$productcode = '';
				else
					$productcode = (string)$productcode;

				$productname = $orderline->productname[0];
				if($productname == null)
					$productname = '';
				else
					$productname = (string)$productname;

				$productid = $orderline->externalreference[0];
				if($productid != null)
				{
					$productid = intval($productid);

					$product = Mage::getModel('catalog/product')->load($productid);

					if($product->getId())
					{
						$productcode = $product->getSku();
						$productname = $product->getName();
					}
					else
					{
						$product = null;
					}
				}

				if(!$product)
				{
					if($request->getQuery('checkproduct')) {
						throw new Exception("external reference not found");
					}
					$product = Mage::getModel('catalog/product');
					$adjustStock = false;
				}

				$qty = (int)$orderline->quantity[0];
				$subtotalinctax = floatval($orderline->linetotalinctax[0]);
				$subtotal = floatval($orderline->linetotal[0]);

				$price = floatval($orderline->price[0]);
				$priceinctax = floatval($orderline->priceinctax[0]);
				$taxamount = $priceinctax - $price;
				$taxpercent = $price == 0 ? 0 : round($priceinctax / $price - 1.0, 2) * 100;
				$weight = floatval($orderline->weight[0]);
				if($weight == 0)
					$weight = 1;

				$weight_total += $weight;

				$orderItem = $quoteConverter->itemToOrderItem($quoteItems[$quoteIdx]);

				$quoteIdx++;

				$orderItem->setStoreId($store->getId());
				$orderItem->setData('product', $product);

				if($productid)
					$orderItem->setProductId($productid);

				if($productid)
					$orderItem->setBaseCost($product->getCost());

				if($productid)
				{
					$orderItem->setOriginalPrice($product->getFinalPrice());
					$orderItem->setBaseOriginalPrice($product->getFinalPrice());
				}
				else
				{
					$orderItem->setOriginalPrice($priceinctax);
					$orderItem->setBaseOriginalPrice($priceinctax);
				}

				$orderItem->setIsVirtual(false);
				$orderItem->setProductType('simple');
				$orderItem->setSku($productcode);
				$orderItem->setName($productname);
				$orderItem->setIsQtyDecimal(false);
				$orderItem->setNoDiscount(true);
				$orderItem->setQtyOrdered($qty);
				$orderItem->setPrice($price);
				$orderItem->setPriceInclTax($priceinctax);
				$orderItem->setBasePrice($price);
				$orderItem->setBasePriceInclTax($priceinctax);
				$orderItem->setTaxPercent($taxpercent);
				$orderItem->setTaxAmount($taxamount * $qty);
				$orderItem->setTaxBeforeDiscount($taxamount * $qty);
				$orderItem->setBaseTaxBeforeDiscount($taxamount * $qty);
				$orderItem->setDiscountAmount(0);
				$orderItem->setWeight($weight);
				$orderItem->setBaseRowTotal($subtotal);
				$orderItem->setBaseRowTotalInclTax($subtotalinctax);
				$orderItem->setRowTotal($subtotal);
				$orderItem->setRowTotalInclTax($subtotalinctax);
				$orderItem->setWeeeTaxApplied(serialize(array()));

				$order->addItem($orderItem);

				if($ordercontent->orderstate != 'cancelled')
				{
					if($adjustStock)
					{
						$stockItem = $product->getStockItem();
						if(!$stockItem)
						{
							$stockItem = Mage::getModel('cataloginventory/stock_item')
											->loadByProduct($product)
											->setStoreId($store->getId());
						}

						$typeId = $product->getTypeId();
						if(!$typeId)
							$typeId = 'simple';

						if(Mage::helper('catalogInventory')->isQty($typeId))
						{
							if($stockItem->canSubtractQty())
							{
								$productsToReindex[$product->getId()] = $product->getId();

								$stockItem->subtractQty($orderItem->getQtyOrdered());
								$stockItem->save();
							}
						}
					}
				}
			}
		}

		$quote->setInventoryProcessed(true);

		$order->setQuote($quote);

		$freightservice = 'Freight';
		$freighttotal =  0.0;
		$freighttotalextax =  0.0;
		$freighttax = 0.0;
		$taxpercent =  0.0;
		$taxrate =  1.0;

		foreach($ordercontent->orderlines->orderline as $orderline)
		{
			if($orderline->productcode[0] == 'FREIGHT')
			{
				$freighttotal += floatval($orderline->linetotalinctax[0]);
				$freighttotalextax += floatval($orderline->linetotal[0]);
				$freighttax = $freighttotal - $freighttotalextax;
				$freightservice = (string)$orderline->productname[0];
			}
		}

		if(strtolower($freightservice) != 'freight')
		{
			$matchFound = false;

			$shippingDescription = $quote->getShippingAddress()->getShippingDescription();
			if($shippingDescription)
			{
				$shippingRates = $quote->getShippingAddress()->getAllShippingRates();

				foreach($shippingRates as $rate)
				{
					$shippingMethodTitle = $rate->getMethodTitle();

					if(strpos($shippingDescription, $shippingMethodTitle) !== false)
					{
						$shippingDescription = str_replace($shippingMethodTitle, $freightservice, $shippingDescription);
						$matchFound = true;
						break;
					}
				}
			}

			if(!$matchFound)
			{
				$shippingDescription = $freightservice;
			}
		}

		if($shippingDescription)
		{
			$order->setShippingDescription($shippingDescription);
		}

		$ordersubtotal -= $freighttotalextax;
		$ordersubtotalincltax -= $freighttotal;
		$ordertaxtotal -= $freighttax;

		$order->setBaseShippingAmount($freighttotal);
		$order->setShippingAmount($freighttotal);

		$order->setBaseShippingInclTax($freighttotal);
		$order->setShippingInclTax($freighttotal);

		$order->setBaseShippingTaxAmount($freighttax);
		$order->setShippingTaxAmount($freighttax);

		$order->setBaseSubtotal($ordersubtotal);
		$order->setSubtotal($ordersubtotal);

		$order->setBaseSubtotalInclTax($ordersubtotalincltax);
		$order->setSubtotalInclTax($ordersubtotalincltax);

		$order->setBaseTaxAmount($ordertaxtotal);
		$order->setTaxAmount($ordertaxtotal);


		$order->setDiscountAmount(0.0);
		$order->setShippingDiscountAmount(0.0);
		$order->setBaseShippingDiscountAmount(0.0);

		$order->setBaseHiddenTaxAmount(0.0);
		$order->setHiddenTaxAmount(0.0);
		$order->setBaseHiddenShippingTaxAmnt(0.0);
		$order->setHiddenShippingTaxAmount(0.0);

		$order->setBaseGrandTotal($ordertotal);
		$order->setGrandTotal($ordertotal);

		$order->setWeight($weight_total);

		$order->setBaseSubtotalInvoiced(0.0);
		$order->setBaseTaxInvoiced(0.0);
		$order->setBaseTotalInvoiced(0.0);
		$order->setSubtotalInvoiced(0.0);
		$order->setTaxInvoiced(0.0);
		$order->setTotalInvoiced(0.0);

		$order->setBaseTotalDue($ordertotal);
		$order->setTotalDue($ordertotal);
		$order->setDue($ordertotal);

		$order->save();

		try
		{
			if(!$request->getQuery('skiporderevent'))
				$order->place();
		}
		catch(Exception $e)
		{
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_PROCESSING, "Exception Occurred Placing Order : ".$e->getMessage());
		}

		/* cancelled, processing, captured, inprogress, complete */
		if($ordercontent->orderstate == 'cancelled') {

			$order->setData('state', Mage_Sales_Model_Order::STATE_CANCELED);
			$order->setData('status', Mage_Sales_Model_Order::STATE_CANCELED);
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_CANCELED, "eBay Order $ebaysalesrecordnumber has been cancelled");

		} else if($ordercontent->orderstate == 'inprogress' || $ordercontent->orderstate == 'processing') {

			$order->setData('state', Mage_Sales_Model_Order::STATE_PROCESSING);
			$order->setData('status', Mage_Sales_Model_Order::STATE_PROCESSING);
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_PROCESSING, "eBay Order $ebaysalesrecordnumber is in progress");

		} else if ($ordercontent->orderstate == 'complete') {

			$order->setData('state', Mage_Sales_Model_Order::STATE_COMPLETE);
			$order->setData('status', Mage_Sales_Model_Order::STATE_COMPLETE);
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_COMPLETE, "eBay Order $ebaysalesrecordnumber is complete");

		} else {

			$order->setData('state', Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
			$order->setData('status', Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, "eBay Order $ebaysalesrecordnumber has been captured");

		}

		if($adjustStock == false) {
			$order->addStatusToHistory($order->getStatus(), "NOTE: Stock level not adjusted, please check your inventory.");
		}

		$order->setBaseTotalPaid(0);
		$order->setTotalPaid(0);
		$order->setBaseTotalDue(0);
		$order->setTotalDue(0);
		$order->setDue(0);

		$payment = $order->getPayment();

		$payment->setMethod('ebay');
		$payment->resetTransactionAdditionalInfo();
		$payment->setTransactionId(0);

		$transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_PAYMENT, null, false, '');
		if($paypaltransactionid)
		{
			$transaction->setTxnId($paypaltransactionid);
			$payment->setLastTransId($paypaltransactionid);
		}

		$payment->setAdditionalInformation('ebaysalesrecordnumber', $ebaysalesrecordnumber);
		$payment->setAdditionalInformation('ebayuser', $ebayusername);

		if($ordercontent->paymentstatus == 'complete')
		{
			$payment->setBaseAmountPaid($ordertotal);
			$payment->setAmountPaid($ordertotal);
			$payment->setBaseAmountAuthorized($ordertotal);
			$payment->setBaseAmountPaidOnline($ordertotal);
			$payment->setAmountAuthorized($ordertotal);
			$payment->setIsTransactionClosed(1);

		}
		else
		{
			$payment->setBaseAmountPaid(0.0);
			$payment->setAmountPaid(0.0);
			$payment->setBaseAmountAuthorized($ordertotal);
			$payment->setBaseAmountPaidOnline($ordertotal);
			$payment->setAmountAuthorized($ordertotal);
			$payment->setIsTransactionClosed(0);
		}

		$quote->setIsActive(false)->save();

		Mage::dispatchEvent('checkout_type_onepage_save_order', array('order'=>$order, 'quote'=>$quote));
		Mage::dispatchEvent('sales_model_service_quote_submit_before', array('order'=>$order, 'quote'=>$quote));

		$payment->save();

		$order->save();

		Mage::dispatchEvent('sales_model_service_quote_submit_success', array('order'=>$order, 'quote'=>$quote));
		Mage::dispatchEvent('sales_model_service_quote_submit_after', array('order'=>$order, 'quote'=>$quote));


		if($ordercontent->paymentstatus == 'complete')
		{
			$invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

			if($invoice->getTotalQty())
			{
				$payment->setBaseAmountPaid(0.0);
				$payment->setAmountPaid(0.0);

				$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
				$invoice->register();
			}
			$invoice->save();

			if(!in_array($invoice->getId(), $invoiceids))
				$invoiceids[] = $invoice->getId();

			$order->setBaseSubtotalInvoiced($ordersubtotal);
			$order->setBaseTaxInvoiced($ordertaxtotal);
			$order->setBaseTotalInvoiced($ordertotal);
			$order->setSubtotalInvoiced($ordersubtotal);
			$order->setTaxInvoiced($ordertaxtotal);
			$order->setTotalInvoiced($ordertotal);
			$order->save();
		}

		$response = $this->getResponse();

		$response->clearAllHeaders();
		//@codingStandardsIgnoreStart
		if(function_exists('http_response_code'))
			http_response_code(200);
		//@codingStandardsIgnoreEnd
		$response->setHttpResponseCode(200);
		$response->setRawHeader('HTTP/1.0 200 OK');
		$response->setRawHeader('Status: 200 OK');
		$response->setHeader('Content-Type', 'application/json');
		$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
		$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
		$response->setHeader('Pragma', 'no-cache', true);
		$response->setBody(Zend_Json::encode(array( 'ack' => 'ok', 'orderid' => $order->getIncrementId())));

		if(!in_array($order->getId(), $orderids))
			$orderids[] = $order->getId();
	}

	private function ProcessOrderSync($quote, $order, $xml, &$productsToReindex, &$orderids, &$invoiceids, $store, $request)
	{

		$orderstatus = $order->getStatus();
		$ordercontent = $xml->entry->content->children('http://api.codisto.com/schemas/2009/');

		$order->setCodistoMerchantid((string)$ordercontent->merchantid);

		$paypaltransactionid = $ordercontent->orderpayments[0]->orderpayment->transactionid;

		$quoteConverter =  Mage::getModel('sales/convert_quote');

		$customer = $quote->getCustomer();
		if($customer)
			$order->setCustomer($customer);

		$orderBillingAddress = $order->getBillingAddress();
		$quoteBillingAddress = $quote->getBillingAddress();

		$orderBillingAddress->setPrefix($quoteBillingAddress->getPrefix());
		$orderBillingAddress->setFirstname($quoteBillingAddress->getFirstname());
		$orderBillingAddress->setMiddlename($quoteBillingAddress->getMiddlename());
		$orderBillingAddress->setLastname($quoteBillingAddress->getLastname());
		$orderBillingAddress->setSuffix($quoteBillingAddress->getSuffix());
		$orderBillingAddress->setCompany($quoteBillingAddress->getCompany());
		$orderBillingAddress->setStreet($quoteBillingAddress->getStreet());
		$orderBillingAddress->setCity($quoteBillingAddress->getCity());
		$orderBillingAddress->setRegion($quoteBillingAddress->getRegion());
		$orderBillingAddress->setRegionId($quoteBillingAddress->getRegionId());
		$orderBillingAddress->setPostcode($quoteBillingAddress->getPostcode());
		$orderBillingAddress->setCountryId($quoteBillingAddress->getCountryId());
		$orderBillingAddress->setTelephone($quoteBillingAddress->getTelephone());
		$orderBillingAddress->setFax($quoteBillingAddress->getFax());
		$orderBillingAddress->setEmail($quoteBillingAddress->getEmail());

		$orderShippingAddress = $order->getShippingAddress();
		$quoteShippingAddress = $quote->getShippingAddress();

		$orderShippingAddress->setPrefix($quoteShippingAddress->getPrefix());
		$orderShippingAddress->setFirstname($quoteShippingAddress->getFirstname());
		$orderShippingAddress->setMiddlename($quoteShippingAddress->getMiddlename());
		$orderShippingAddress->setLastname($quoteShippingAddress->getLastname());
		$orderShippingAddress->setSuffix($quoteShippingAddress->getSuffix());
		$orderShippingAddress->setCompany($quoteShippingAddress->getCompany());
		$orderShippingAddress->setStreet($quoteShippingAddress->getStreet());
		$orderShippingAddress->setCity($quoteShippingAddress->getCity());
		$orderShippingAddress->setRegion($quoteShippingAddress->getRegion());
		$orderShippingAddress->setRegionId($quoteShippingAddress->getRegionId());
		$orderShippingAddress->setPostcode($quoteShippingAddress->getPostcode());
		$orderShippingAddress->setCountryId($quoteShippingAddress->getCountryId());
		$orderShippingAddress->setTelephone($quoteShippingAddress->getTelephone());
		$orderShippingAddress->setFax($quoteShippingAddress->getFax());
		$orderShippingAddress->setEmail($quoteShippingAddress->getEmail());

		$ebaysalesrecordnumber = (string)$ordercontent->ebaysalesrecordnumber;
		if(!$ebaysalesrecordnumber)
			$ebaysalesrecordnumber = '';

		$currencyCode = (string)$ordercontent->transactcurrency[0];
		$ordertotal = floatval($ordercontent->ordertotal[0]);
		$ordersubtotal = floatval($ordercontent->ordersubtotal[0]);
		$ordertaxtotal = floatval($ordercontent->ordertaxtotal[0]);

		$ordersubtotal = $store->roundPrice($ordersubtotal);
		$ordersubtotalincltax = $store->roundPrice($ordersubtotal + $ordertaxtotal);
		$ordertotal = $store->roundPrice($ordertotal);

		$freightcarrier = 'Post';
		$freightservice = 'Freight';
		$freighttotal =  0.0;
		$freighttotalextax =  0.0;
		$freighttax = 0.0;
		$taxpercent =  0.0;
		$taxrate =  1.0;

		foreach($ordercontent->orderlines->orderline as $orderline)
		{
			if($orderline->productcode[0] == 'FREIGHT')
			{
				$freighttotal += floatval($orderline->linetotalinctax[0]);
				$freighttotalextax += floatval($orderline->linetotal[0]);
				$freighttax = $freighttotal - $freighttotalextax;
				$freightservice = (string)$orderline->productname[0];
			}
		}

		if(strtolower($freightservice) != 'freight')
		{
			$matchFound = false;

			$shippingDescription = $quote->getShippingAddress()->getShippingDescription();
			if($shippingDescription)
			{
				$shippingRates = $quote->getShippingAddress()->getAllShippingRates();

				foreach($shippingRates as $rate)
				{
					$shippingMethodTitle = $rate->getMethodTitle();

					if(strpos($shippingDescription, $shippingMethodTitle) !== false)
					{
						$shippingDescription = str_replace($shippingMethodTitle, $freightservice, $shippingDescription);
						$matchFound = true;
						break;
					}
				}
			}

			if(!$matchFound)
			{
				$shippingDescription = $freightservice;
			}
		}

		if($shippingDescription)
		{
			$order->setShippingDescription($shippingDescription);
		}

		$ordersubtotal -= $freighttotalextax;
		$ordersubtotalincltax -= $freighttotal;
		$ordertaxtotal -= $freighttax;

		$order->setBaseShippingAmount($freighttotal);
		$order->setShippingAmount($freighttotal);

		$order->setBaseShippingInclTax($freighttotal);
		$order->setShippingInclTax($freighttotal);

		$order->setBaseShippingTaxAmount($freighttax);
		$order->setShippingTaxAmount($freighttax);

		$order->setBaseSubtotal($ordersubtotal);
		$order->setSubtotal($ordersubtotal);

		$order->setBaseSubtotalInclTax($ordersubtotalincltax);
		$order->setSubtotalInclTax($ordersubtotalincltax);

		$order->setBaseTaxAmount($ordertaxtotal);
		$order->setTaxAmount($ordertaxtotal);

		$order->setDiscountAmount(0.0);
		$order->setShippingDiscountAmount(0.0);
		$order->setBaseShippingDiscountAmount(0.0);

		$order->setBaseHiddenTaxAmount(0.0);
		$order->setHiddenTaxAmount(0.0);
		$order->setBaseHiddenShippingTaxAmnt(0.0);
		$order->setHiddenShippingTaxAmount(0.0);

		$order->setBaseGrandTotal($ordertotal);
		$order->setGrandTotal($ordertotal);

		$orderlineStockReserved = array();
		foreach($order->getAllItems() as $item)
		{
			$productId = $item->getProductId();
			if($productId || $productId == 0)
			{
				if(isset($orderlineStockReserved[$productId]))
					$orderlineStockReserved[$productId] += $item->getQtyOrdered();
				else
					$orderlineStockReserved[$productId] = $item->getQtyOrdered();
			}
		}

		$visited = array();

		$weight_total = 0;

		$quoteItems = $quote->getItemsCollection()->getItems();
		$quoteIdx = 0;

		$totalquantity = 0;
		foreach($ordercontent->orderlines->orderline as $orderline)
		{
			if($orderline->productcode[0] != 'FREIGHT')
			{
				$adjustStock = true;

				$product = null;

				$productcode = $orderline->productcode[0];
				if($productcode == null)
					$productcode = '';
				else
					$productcode = (string)$productcode;

				$productname = $orderline->productname[0];
				if($productname == null)
					$productname = '';
				else
					$productname = (string)$productname;

				$productid = $orderline->externalreference[0];

				if($productid != null)
				{
					$productid = intval($productid);

					$product = Mage::getModel('catalog/product')->load($productid);

					if($product->getId())
					{
						$productcode = $product->getSku();
						$productname = $product->getName();
					}
					else
					{
						$product = Mage::getModel('catalog/product');
					}
				}

				if(!$product)
				{
					if($request->getQuery('checkproduct')) {
						throw new Exception('externalreference not found');
					}
					$product = Mage::getModel('catalog/product');
					$adjustStock = false;
				}

				$qty = (int)$orderline->quantity[0];
				$subtotalinctax = floatval($orderline->linetotalinctax[0]);
				$subtotal = floatval($orderline->linetotal[0]);

				$totalquantity += $qty;

				$price = floatval($orderline->price[0]);
				$priceinctax = floatval($orderline->priceinctax[0]);
				$taxamount = $priceinctax - $price;
				$taxpercent = $price == 0 ? 0 : round($priceinctax / $price - 1.0, 2) * 100;
				$weight = floatval($orderline->weight[0]);
				if($weight == 0)
					$weight = 1;

				$weight_total += $weight;

				$itemFound = false;
				foreach($order->getAllItems() as $item)
				{
					if(!isset($visited[$item->getId()]))
					{
						if($productid)
						{
							if($item->getProductId() == $productid)
							{
								$itemFound = true;
								$visited[$item->getId()] = true;
								break;
							}
						}
						else
						{
							if($item->getSku() == $productcode)
							{
								$itemFound = true;
								$visited[$item->getId()] = true;
								break;
							}
						}
					}
				}

				if(!$itemFound)
				{
					$item = $quoteConverter->itemToOrderItem($quoteItems[$quoteIdx]);
				}

				$quoteIdx++;

				$item->setStoreId($store->getId());

				$item->setData('product', $product);

				if($productid)
					$item->setProductId($productid);

				if($productid)
					$item->setBaseCost($product->getCost());

				if($productid)
				{
					$item->setOriginalPrice($product->getFinalPrice());
					$item->setBaseOriginalPrice($product->getFinalPrice());
				}
				else
				{
					$item->setOriginalPrice($priceinctax);
					$item->setBaseOriginalPrice($priceinctax);
				}

				$item->setIsVirtual(false);
				$item->setProductType('simple');
				$item->setSku($productcode);
				$item->setName($productname);
				$item->setIsQtyDecimal(false);
				$item->setNoDiscount(true);
				$item->setQtyOrdered($qty);
				$item->setPrice($price);
				$item->setPriceInclTax($priceinctax);
				$item->setBasePrice($price);
				$item->setBasePriceInclTax($priceinctax);
				$item->setTaxPercent($taxpercent);
				$item->setTaxAmount($taxamount * $qty);
				$item->setTaxBeforeDiscount($taxamount * $qty);
				$item->setBaseTaxBeforeDiscount($taxamount * $qty);
				$item->setDiscountAmount(0);
				$item->setWeight($weight);
				$item->setBaseRowTotal($subtotal);
				$item->setBaseRowTotalInclTax($subtotalinctax);
				$item->setRowTotal($subtotal);
				$item->setRowTotalInclTax($subtotalinctax);
				$item->setWeeeTaxApplied(serialize(array()));

				if(!$itemFound)
					$order->addItem($item);

				if($ordercontent->orderstate != 'cancelled')
				{
					if($adjustStock)
					{
						$stockItem = $product->getStockItem();
						if(!$stockItem)
						{
							$stockItem = Mage::getModel('cataloginventory/stock_item')
											->loadByProduct($product)
											->setStoreId($store->getId());
						}

						$typeId = $product->getTypeId();
						if(!$typeId)
							$typeId = 'simple';

						if(Mage::helper('catalogInventory')->isQty($typeId))
						{
							if($stockItem->canSubtractQty())
							{
								$stockReserved = isset($orderlineStockReserved[$productid]) ? $orderlineStockReserved[$productid] : 0;

								$stockMovement = $qty - $stockReserved;

								if($stockMovement > 0)
								{
									$productsToReindex[$product->getId()] = $product->getId();

									$stockItem->subtractQty($stockMovement);
									$stockItem->save();
								}
								else if($stockMovement < 0)
								{
									$productsToReindex[$product->getId()] = $product->getId();

									$stockMovement = abs($stockMovement);

									$stockItem->addQty($stockMovement);
									$stockItem->save();
								}
							}
						}
					}
				}
			}
		}

		$visited = array();
		foreach($order->getAllItems() as $item)
		{
			$itemFound = false;

			$orderlineIndex = 0;
			foreach($ordercontent->orderlines->orderline as $orderline)
			{
				if(!isset($visited[$orderlineIndex]) &&
						$orderline->productcode[0] != 'FREIGHT')
				{
					$productcode = $orderline->productcode[0];
					if($productcode == null)
						$productcode = '';
					else
						$productcode = (string)$productcode;

					$productname = $orderline->productname[0];
					if($productname == null)
						$productname = '';
					else
						$productname = (string)$productname;

					$productid = $orderline->externalreference[0];
					if($productid != null)
					{
						$productid = intval($productid);
					}

					if($productid)
					{
						if($item->getProductId() == $productid)
						{
							$itemFound = true;
							$visited[$orderlineIndex] = true;
						}
					}
					else
					{
						if($item->getSku() == $productcode)
						{
							$itemFound = true;
							$visited[$orderlineIndex] = true;
						}
					}
				}

				$orderlineIndex++;
			}

			if(!$itemFound)
				$item->delete();
		}

		$order->setTotalQtyOrdered((int)$totalquantity);
		$order->setWeight($weight_total);

		/* States: cancelled, processing, captured, inprogress, complete */
		if(($ordercontent->orderstate == 'captured' ||
			$ordercontent->paymentstatus != 'complete') &&
			($orderstatus!=Mage_Sales_Model_Order::STATE_PROCESSING &&
				$orderstatus!=Mage_Sales_Model_Order::STATE_PENDING_PAYMENT &&
				$orderstatus!=Mage_Sales_Model_Order::STATE_NEW))
		{

			$order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
			$order->setStatus(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is pending payment");
		}

		if($ordercontent->orderstate == 'cancelled' && $orderstatus!=Mage_Sales_Model_Order::STATE_CANCELED)
		{
			$order->setState(Mage_Sales_Model_Order::STATE_CANCELED);
			$order->setStatus(Mage_Sales_Model_Order::STATE_CANCELED);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber has been cancelled");
		}

		if(($ordercontent->orderstate == 'inprogress' || $ordercontent->orderstate == 'processing') &&
			$ordercontent->paymentstatus == 'complete' &&
			$orderstatus!=Mage_Sales_Model_Order::STATE_PROCESSING &&
			$orderstatus!=Mage_Sales_Model_Order::STATE_COMPLETE)
		{
			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING);
			$order->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is in progress");
		}

		if($ordercontent->orderstate == 'complete' &&
			$orderstatus!=Mage_Sales_Model_Order::STATE_COMPLETE)
		{

			$order->setData('state', Mage_Sales_Model_Order::STATE_COMPLETE);
			$order->setData('status', Mage_Sales_Model_Order::STATE_COMPLETE);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is complete");
		}

		if(
			($ordercontent->orderstate == 'cancelled' && $orderstatus!= Mage_Sales_Model_Order::STATE_CANCELED) ||
			($ordercontent->orderstate != 'cancelled' && $orderstatus == Mage_Sales_Model_Order::STATE_CANCELED))
		{
			foreach($ordercontent->orderlines->orderline as $orderline)
			{
				if($orderline->productcode[0] != 'FREIGHT')
				{
					$catalog = Mage::getModel('catalog/product');
					$prodid = $catalog->getIdBySku((string)$orderline->productcode[0]);

					if($prodid)
					{
						$product = Mage::getModel('catalog/product')->load($prodid);
						if($product)
						{
							$qty = $orderline->quantity[0];
							$totalquantity += $qty;

							$stockItem = $product->getStockItem();
							if(!$stockItem)
							{
								$stockItem = Mage::getModel('cataloginventory/stock_item')
												->loadByProduct($product)
												->setStoreId($store->getId());
							}

							$typeId = $product->getTypeId();
							if(!$typeId)
								$typeId = 'simple';

							if(Mage::helper('catalogInventory')->isQty($typeId))
							{
								if($stockItem->canSubtractQty())
								{
									if($ordercontent->orderstate == 'cancelled') {

										$productsToReindex[$product->getId()] = $product->getId();

										$stockItem->addQty(intval($qty));

									} else {

										$productsToReindex[$product->getId()] = $product->getId();

										$stockItem->subtractQty(intval($qty));

									}

									$stockItem->save();
								}
							}
						}
					}

				}
			}
		}

		$order->save();

		if(!$order->hasInvoices())
		{
			if($ordercontent->paymentstatus == 'complete' && $order->canInvoice())
			{
				$order->setBaseTotalPaid($ordertotal);
				$order->setTotalPaid($ordertotal);
				$order->setBaseTotalDue(0.0);
				$order->setTotalDue(0.0);
				$order->setDue(0.0);

				$payment = $order->getPayment();

				if($paypaltransactionid)
				{
					$transaction = $payment->getTransaction(0);
					if($transaction)
					{
						$transaction->setTxnId($paypaltransactionid);
						$payment->setLastTransId($paypaltransactionid);
					}
				}

				$payment->setMethod('ebay');
				$payment->setParentTransactionId(null)
					->setIsTransactionClosed(1);

				$payment->save();

				$invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

				if($invoice->getTotalQty())
				{
					$payment->setBaseAmountPaid(0.0);
					$payment->setAmountPaid(0.0);

					$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
					$invoice->register();
				}
				$invoice->save();

				if(!in_array($invoice->getId(), $invoiceids))
					$invoiceids[] = $invoice->getId();

				$order->setBaseSubtotalInvoiced($ordersubtotal);
				$order->setBaseTaxInvoiced($ordertaxtotal);
				$order->setBaseTotalInvoiced($ordertotal);
				$order->setSubtotalInvoiced($ordersubtotal);
				$order->setTaxInvoiced($ordertaxtotal);
				$order->setTotalInvoiced($ordertotal);
				$order->save();
			}
		}

		$response = $this->getResponse();

		$response->clearAllHeaders();
		//@codingStandardsIgnoreStart
		if(function_exists('http_response_code'))
			http_response_code(200);
		//@codingStandardsIgnoreEnd
		$response->setHttpResponseCode(200);
		$response->setRawHeader('HTTP/1.0 200 OK');
		$response->setRawHeader('Status: 200 OK');
		$response->setHeader('Content-Type', 'application/json');
		$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
		$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
		$response->setHeader('Pragma', 'no-cache', true);
		$response->setBody(Zend_Json::encode(array( 'ack' => 'ok', 'orderid' => $order->getIncrementId())));

		if(!in_array($order->getId(), $orderids))
			$orderids[] = $order->getId();
	}

	private function ProcessQuote($quote, $xml, $store, $request)
	{

		$connection = Mage::getSingleton('core/resource')->getConnection('core_write');

		$ordercontent = $xml->entry->content->children('http://api.codisto.com/schemas/2009/');

		$register_customer = (string)$ordercontent->register_customer == 'false' ? false : true;

		$websiteId = $store->getWebsiteId();

		$billing_address = $ordercontent->orderaddresses->orderaddress[0];
		$billing_first_name = $billing_last_name = '';

		if(strpos($billing_address->name, ' ') !== false) {
			$billing_name = explode(' ', $billing_address->name, 2);
			$billing_first_name = $billing_name[0];
			$billing_last_name = $billing_name[1];
		} else {
			$billing_first_name = $billing_address->name;
		}

		$billing_phone = (string)$billing_address->phone;
		if(!$billing_phone)
			$billing_phone = 'Not Available';

		$shipping_address = $ordercontent->orderaddresses->orderaddress[1];
		$shipping_first_name = $shipping_last_name = '';

		if(strpos($shipping_address->name, ' ') !== false) {
			$shipping_name = explode(' ', $shipping_address->name, 2);
			$shipping_first_name = $shipping_name[0];
			$shipping_last_name = $shipping_name[1];
		} else {
			$shipping_first_name = $shipping_address->name;
		}

		$shipping_phone = (string)$shipping_address->phone;
		if(!$shipping_phone)
			$shipping_phone = 'Not Available';

		$email = (string)$billing_address->email;
		if(!$email || $email == 'Invalid Request')
			$email = 'mail@example.com';

		$regionCollection = $this->getRegionCollection($billing_address->countrycode);

		$regionsel_id = 0;
		foreach($regionCollection as $region)
		{
			if(in_array($billing_address->division, array($region['code'], $region['name'])))
			{

				$regionsel_id = $region['region_id'];
			}
		}

		$addressBilling = array(
			'email' => $email,
			'prefix' => '',
			'suffix' => '',
			'company' => (string)$billing_address->companyname,
			'firstname' => (string)$billing_first_name,
			'middlename' => '',
			'lastname' => (string)$billing_last_name,
			'street' => (string)$billing_address->address1.($billing_address->address2 ? "\n".$billing_address->address2 : ''),
			'city' => (string)$billing_address->place,
			'postcode' => (string)$billing_address->postalcode,
			'telephone' => (string)$billing_phone,
			'fax' => '',
			'country_id' => (string)$billing_address->countrycode,
			'region_id' => $regionsel_id, // id from directory_country_region table
			'region' => (string)$billing_address->division
		);

		$regionsel_id_ship = 0;
		foreach($regionCollection as $region)
		{
			if(in_array($shipping_address->division, array($region['code'], $region['name'])))
			{
				$regionsel_id_ship = $region['region_id'];
			}
		}

		$addressShipping = array(
			'email' => $email,
			'prefix' => '',
			'suffix' => '',
			'company' => (string)$shipping_address->companyname,
			'firstname' => (string)$shipping_first_name,
			'middlename' => '',
			'lastname' => (string)$shipping_last_name,
			'street' => (string)$shipping_address->address1.($shipping_address->address2 ? "\n".$shipping_address->address2 : ''),
			'city' => (string)$shipping_address->place,
			'postcode' => (string)$shipping_address->postalcode,
			'telephone' => (string)$shipping_phone,
			'fax' => '',
			'country_id' => (string)$shipping_address->countrycode,
			'region_id' => $regionsel_id_ship, // id from directory_country_region table
			'region' => (string)$shipping_address->division
		);

		$customer = null;

		if($register_customer && $email != 'mail@example.com')
		{
			$customer = Mage::getModel('customer/customer');
			$customer->setWebsiteId($websiteId);
			$customer->setStoreId($store->getId());

			for($Retry = 0; ; $Retry++)
			{
				try
				{
					$connection->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
					$connection->beginTransaction();

					$customer->loadByEmail($email);

					if(!$customer->getId())
					{
						$ebayGroup = Mage::getModel('customer/group');
						$ebayGroup->load('eBay', 'customer_group_code');
						if(!$ebayGroup->getId())
						{
							$defaultGroup = Mage::getModel('customer/group')->load(1);

							$ebayGroup->setCode('eBay');
							$ebayGroup->setTaxClassId($defaultGroup->getTaxClassId());
							$ebayGroup->save();
						}

						$customerGroupId = $ebayGroup->getId();

						$customer->setWebsiteId($websiteId);
						$customer->setStoreId($store->getId());
						$customer->setEmail($email);
						$customer->setFirstname((string)$billing_first_name);
						$customer->setLastname((string)$billing_last_name);
						$customer->setPassword('');
						$customer->setGroupId($customerGroupId);
						$customer->save();
						$customer->setConfirmation(null);
						$customer->save();

						$customerId = $customer->getId();

						$customerAddress = Mage::getModel('customer/address');
						$customerAddress->setData($addressBilling)
							->setCustomerId($customer->getId())
							->setIsDefaultBilling(1)
							->setSaveInAddressBook(1);
						$customerAddress->save();


						$customerAddress->setData($addressShipping)
							->setCustomerId($customer->getId())
							->setIsDefaultShipping(1)
							->setSaveInAddressBook(1);
						$customerAddress->save();
					}
					else
					{
						$customerId = $customer->getId();
						$customerGroupId = $customer->getGroupId();
					}

					$connection->commit();
					break;
				}
				catch(Exception $e)
				{
					if($Retry < 5)
					{
						if($e->getCode() == 40001)
						{
							$connection->rollback();
							sleep($Retry * 10);
							continue;
						}
					}

					$connection->rollback();
					throw $e;
				}
			}
		}

		$currencyCode = (string)$ordercontent->transactcurrency[0];
		$ordertotal = floatval($ordercontent->ordertotal[0]);
		$ordersubtotal = floatval($ordercontent->ordersubtotal[0]);
		$ordertaxtotal = floatval($ordercontent->ordertaxtotal[0]);

		$ordersubtotal = $store->roundPrice($ordersubtotal);
		$ordersubtotalincltax = $store->roundPrice($ordersubtotal + $ordertaxtotal);
		$ordertotal = $store->roundPrice($ordertotal);

		$quote->setIsSuperMode(true);
		$quote->setStore($store);
		$quote->setCheckoutMethod(Mage_Checkout_Model_Type_Onepage::METHOD_GUEST);
		$quote->save();

		if($customer)
		{
			$quote->assignCustomer($customer);
			$quote->setCustomerId($customerId);
			$quote->setCustomerGroupId($customerGroupId);
		}
		else
		{
			$quote->setCustomerId(null);
			$quote->setCustomerEmail($email);
			$quote->setCustomerFirstName((string)$billing_first_name);
			$quote->setCustomerLastName((string)$billing_last_name);
			$quote->setCustomerIsGuest(true);
			$quote->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);
		}

		$billingAddress = $quote->getBillingAddress();
		if($customer)
			$billingAddress->setCustomer($customer);
		$billingAddress->addData($addressBilling);

		$shippingAddress = $quote->getShippingAddress();
		if($customer)
			$shippingAddress->setCustomer($customer);
		$shippingAddress->addData($addressShipping);
		$shippingAddress->implodeStreetAddress();

		$totalitemcount = 0;
		$totalitemqty = 0;

		foreach($ordercontent->orderlines->orderline as $orderline)
		{
			if($orderline->productcode[0] != 'FREIGHT')
			{
				$adjustStock = true;

				$product = null;
				$productcode = (string)$orderline->productcode;
				if($productcode == null)
					$productcode = '';
				$productname = (string)$orderline->productname;
				if($productname == null)
					$productname = '';

				$productid = $orderline->externalreference[0];
				if($productid != null)
				{
					$productid = intval($productid);

					$product = Mage::getModel('catalog/product')->load($productid);

					if($product->getId())
					{
						$productcode = $product->getSku();
						$productname = $product->getName();
					}
					else
					{
						$product = null;
					}
				}

				if(!$product)
				{
					if($request->getQuery('checkproduct')) {
						throw new Exception('externalreference not found');
					}
					$product = Mage::getModel('catalog/product');
				}

				$product->setIsSuperMode(true);

				$qty = (int)$orderline->quantity[0];
				$subtotalinctax = floatval($orderline->linetotalinctax[0]);
				$subtotal = floatval($orderline->linetotal[0]);

				$totalitemcount += 1;
				$totalitemqty += $qty;

				$price = floatval($orderline->price[0]);
				$priceinctax = floatval($orderline->priceinctax[0]);
				$taxamount = $priceinctax - $price;
				$taxpercent = $price == 0 ? 0 : round($priceinctax / $price - 1.0, 2) * 100;
				$weight = floatval($orderline->weight[0]);

				$item = Mage::getModel('sales/quote_item');
				$item->setStoreId($store->getId());
				$item->setQuote($quote);

				$item->setData('product', $product);
				$item->setProductId($productid);
				$item->setProductType('simple');
				$item->setIsRecurring(false);

				if($productid)
					$item->setTaxClassId($product->getTaxClassId());

				if($productid)
					$item->setBaseCost($product->getCost());

				$item->setSku($productcode);
				$item->setName($productname);
				$item->setIsVirtual(false);
				$item->setIsQtyDecimal(false);
				$item->setNoDiscount(true);
				$item->setWeight($weight);
				$item->setData('qty', $qty);
				$item->setPrice($price);
				$item->setBasePrice($price);
				$item->setCustomPrice($price);
				$item->setDiscountPercent(0);
				$item->setDiscountAmount(0);
				$item->setBaseDiscountAmount(0);
				$item->setTaxPercent($taxpercent);
				$item->setTaxAmount($taxamount * $qty);
				$item->setBaseTaxAmount($taxamount * $qty);
				$item->setRowTotal($subtotal);
				$item->setBaseRowTotal($subtotal);
				$item->setRowTotalWithDiscount($subtotal);
				$item->setRowWeight($weight * $qty);
				$item->setOriginalCustomPrice($price);
				$item->setPriceInclTax($priceinctax);
				$item->setBasePriceInclTax($priceinctax);
				$item->setRowTotalInclTax($subtotalinctax);
				$item->setBaseRowTotalInclTax($subtotalinctax);
				$item->setWeeeTaxApplied(serialize(array()));

				$quote->getItemsCollection()->addItem($item);
			}
		}

		$freighttotal = 0.0;
		$freighttotalextax = 0.0;
		$freighttax = 0.0;
		$freightservice = '';

		foreach($ordercontent->orderlines->orderline as $orderline)
		{
			if($orderline->productcode[0] == 'FREIGHT')
			{
				$freighttotal += floatval($orderline->linetotalinctax[0]);
				$freighttotalextax += floatval($orderline->linetotal[0]);
				$freighttax = (float)$freighttotal - $freighttotalextax;
				$freightservice = $orderline->productname[0];
			}
		}

		$ordersubtotal -= $freighttotalextax;
		$ordersubtotalincltax -= $freighttotal;
		$ordertaxtotal -= $freighttax;

		$quotePayment = $quote->getPayment();
		$quotePayment->setMethod('ebay');
		$quotePayment->save();

		$quote->setBaseCurrencyCode($currencyCode);
		$quote->setStoreCurrencyCode($currencyCode);
		$quote->setQuoteCurrencyCode($currencyCode);
		$quote->setItemsCount($totalitemcount);
		$quote->setItemsQty($totalitemqty);
		$quote->setVirtualItemsQty(0);
		$quote->setGrandTotal($ordertotal);
		$quote->setBaseGrandTotal($ordertotal);
		$quote->setSubtotal($ordersubtotal);
		$quote->setBaseSubtotal($ordersubtotal);
		$quote->setSubtotal($ordersubtotal);
		$quote->setBaseSubtotalWithDiscount($ordersubtotal);
		$quote->setSubtotalWithDiscount($ordersubtotal);
		$quote->setData('trigger_recollect', 0);
		$quote->setTotalsCollectedFlag(true);
		$quote->save();

		$customerInstruction = @count($ordercontent->instructions) ? strval($ordercontent->instructions) : '';

		$checkoutSession = Mage::getSingleton('checkout/session');
		$checkoutSession->setCustomer($customer);
		$checkoutSession->replaceQuote($quote);
		$checkoutSession->setData('customer_comment', $customerInstruction);
		$checkoutSession->setData('destination_type', 'residence');

		$shippingAddress = $quote->getShippingAddress();
		$shippingAddress->setSubtotal($ordersubtotal);
		$shippingAddress->setBaseSubtotal($ordersubtotal);
		$shippingAddress->setSubtotalWithDiscount($ordersubtotal);
		$shippingAddress->setBaseSubtotalWithDiscount($ordersubtotal);
		$shippingAddress->setTaxAmount($ordertaxtotal);
		$shippingAddress->setBaseTaxAmount($ordertaxtotal);
		$shippingAddress->setShippingTaxAmount($freighttax);
		$shippingAddress->setBaseShippingTaxAmount($freighttax);
		$shippingAddress->setDiscountAmount(0);
		$shippingAddress->setBaseDiscountAmount(0);
		$shippingAddress->setGrandTotal($ordertotal);
		$shippingAddress->setBaseGrandTotal($ordertotal);
		$shippingAddress->setAppliedTaxes(array());
		$shippingAddress->setShippingDiscountAmount(0);
		$shippingAddress->setBaseShippingDiscountAmount(0);
		$shippingAddress->setSubtotalInclTax($ordersubtotalincltax);
		$shippingAddress->setBaseSubtotalTotalInclTax($ordersubtotalincltax);

		$freightcode = 'flatrate_flatrate';
		$freightcarrier = 'Post';
		$freightcarriertitle = 'Post';
		$freightmethod = 'Freight';
		$freightmethodtitle = 'Freight';
		$freightmethoddescription = '';
		$freighttotal =  0.0;
		$freighttotalextax =  0.0;
		$freighttax = 0.0;
		$taxpercent =  0.0;
		$taxrate =  1.0;

		$freightcost = null;
		$freightRate = null;

		try {

			if(!$request->getQuery('skipshipping')) {
				$shippingRequest = Mage::getModel('shipping/rate_request');
				$shippingRequest->setAllItems($quote->getAllItems());
				$shippingRequest->setDestCountryId($shippingAddress->getCountryId());
				$shippingRequest->setDestRegionId($shippingAddress->getRegionId());
				$shippingRequest->setDestRegionCode($shippingAddress->getRegionCode());
				$shippingRequest->setDestStreet($shippingAddress->getStreet(-1));
				$shippingRequest->setDestCity($shippingAddress->getCity());
				$shippingRequest->setDestPostcode($shippingAddress->getPostcode());
				$shippingRequest->setPackageValue($quote->getBaseSubtotal());
				$shippingRequest->setPackageValueWithDiscount($quote->getBaseSubtotalWithDiscount());
				$shippingRequest->setPackageWeight($quote->getWeight());
				$shippingRequest->setPackageQty($quote->getItemQty());
				$shippingRequest->setPackagePhysicalValue($quote->getBaseSubtotal());
				$shippingRequest->setFreeMethodWeight($quote->getWeight());
				$shippingRequest->setStoreId($store->getId());
				$shippingRequest->setWebsiteId($store->getWebsiteId());
				$shippingRequest->setFreeShipping(false);
				$shippingRequest->setBaseCurrency($currencyCode);
				$shippingRequest->setPackageCurrency($currencyCode);
				$shippingRequest->setBaseSubtotalInclTax($quote->getBaseSubtotalInclTax());

				$shippingResult = Mage::getModel('shipping/shipping')->collectRates($shippingRequest)->getResult();

				$shippingRates = $shippingResult->getAllRates();

				foreach($shippingRates as $shippingRate)
				{
					if($shippingRate instanceof Mage_Shipping_Model_Rate_Result_Method)
					{
						if(is_null($freightcost) || (!is_null($shippingRate->getPrice()) && $shippingRate->getPrice() < $freightcost))
						{
							$isPickup = $shippingRate->getPrice() == 0 &&
										(preg_match('/(?:^|\W|_)pick\s*up(?:\W|_|$)/i', strval($shippingRate->getMethod())) ||
											preg_match('/(?:^|\W|_)pick\s*up(?:\W|_|$)/i', strval($shippingRate->getCarrierTitle())) ||
											preg_match('/(?:^|\W|_)pick\s*up(?:\W|_|$)/i', strval($shippingRate->getMethodTitle())));

							if(!$isPickup)
							{
								$freightRate = Mage::getModel('sales/quote_address_rate')
												->importShippingRate($shippingRate);

								$freightcode = $freightRate->getCode();
								$freightcarrier = $freightRate->getCarrier();
								$freightcarriertitle = $freightRate->getCarrierTitle();
								$freightmethod = $freightRate->getMethod();
								$freightmethodtitle = $freightRate->getMethodTitle();
								$freightmethoddescription = $freightRate->getMethodDescription();
							}
						}
					}
				}
			}
		}
		catch(Exception $e)
		{

		}

		if(!$freightRate)
		{
			$freightRate = Mage::getModel('sales/quote_address_rate');
			$freightRate->setCode($freightcode)
				->setCarrier($freightcarrier)
				->setCarrierTitle($freightcarriertitle)
				->setMethod($freightmethod)
				->setMethodTitle($freightmethodtitle)
				->setMethodDescription($freightmethoddescription)
				->setPrice($freighttotal);
		}

		$shippingAddress->addShippingRate($freightRate);
		$shippingAddress->setShippingMethod($freightcode);
		$shippingAddress->setShippingDescription($freightcarriertitle.' - '.$freightmethodtitle);
		$shippingAddress->setShippingAmount($freighttotal);
		$shippingAddress->setBaseShippingAmount($freighttotal);
		$shippingAddress->save();
	}

	private function getRegionCollection($countryCode)
	{
		$regionCollection = Mage::getModel('directory/region_api')->items($countryCode);
		return $regionCollection;
	}
}
?>
