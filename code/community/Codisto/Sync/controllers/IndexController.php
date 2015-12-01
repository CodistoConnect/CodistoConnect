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

class Codisto_Sync_IndexController extends Codisto_Sync_Controller_BaseController
{
	protected $_PayPalmethodType = Mage_Paypal_Model_Config::METHOD_WPP_EXPRESS;

	public function calcAction()
	{
		set_time_limit(0);
		ignore_user_abort(false);

		$request = $this->getRequest();
		$response = $this->getResponse();

		$storeId = $request->getQuery('storeid') == null ? 0 : (int)$request->getQuery('storeid');
		$store = Mage::app()->getStore($storeId);

		$model = Mage::getModel('catalog/product');

		$cart = Mage::getSingleton('checkout/cart');

		$postalcode = $request->getPost('POSTALCODE');
		$division = $request->getPost('DIVISION');
		$countrycode = $request->getPost('COUNTRYCODE');

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
				$regionid = $region->getId();
		}
		else
		{
			$region = Mage::getModel('directory/region')->loadByName($division, $countrycode);
			if($region)
				$regionid = $region->getId();
		}

		for($inputidx = 0; ; $inputidx++)
		{
			$productcode = $request->getPost('PRODUCTCODE('.$inputidx.')');
			$id = $model->getIdBySku($productcode);

			$productqty = $request->getPost('PRODUCTQUANTITY('.$inputidx.')');
			if(!$productqty && $productqty !=0)
				$productqty = 1;

			if(!$productcode)
				break;

			if($id)
			{
				$product = $model->load($id);

				if($product)
				{
					$item = Mage::getModel('sales/quote_item');
					$item->setStoreId($storeId);
					$item->setQuote($cart->getQuote());

					$item->setProduct($product);

					$cart->getQuote()->getItemsCollection()->addItem($item);
				}
			}
		}

		$address = $cart->getQuote()
			->setStore($store)
			->getShippingAddress()
			->setCountryId((string) $countrycode)
			->setPostcode((string) $postalcode);

		if($regionid)
			$address->setRegionId((string) $regionid);

		$cart->save();

		$rates = $cart->getQuote()->getShippingAddress()->getShippingRatesCollection();

		$output = '';
		$outputidx = 0;

		foreach ($rates as $rate) {
			$output .= 'FREIGHTNAME('.$outputidx.')=Freight&FREIGHTCHARGEINCTAX('.$outputidx.')='.$rate->getPrice() . '&';

			$outputidx++;
		}

		try
		{
			$cart->getQuote()
				->setIsActive(false)
				->delete();
		}
		catch(Exception $e)
		{

		}

		$response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
		$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
		$response->setHeader('Pragma', 'no-cache', true);
		$response->setBody($output);
	}

	public function indexAction()
	{
		set_time_limit(0);
		ignore_user_abort(false);

		$request = $this->getRequest();
		$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		$contenttype = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
		$server = $request->getServer();

		if($method == 'POST')
		{
			if($contenttype == 'text/xml')
			{
				$xml = simplexml_load_string(file_get_contents('php://input'));

				$ordercontent = $xml->entry->content->children('http://api.codisto.com/schemas/2009/');

				$storeId = @count($ordercontent->storeid) ? (int)$ordercontent->storeid : 0;

				if(!$this->getConfig($storeId))
				{
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

				if($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
				{
					$productsToReindex = array();

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

					Mage::app()->setCurrentStore($store);

					$quote = null;

					for($Retry = 0; ; $Retry++)
					{
						$order = Mage::getModel('sales/order')->getCollection()->addAttributeToFilter('codisto_orderid', $ordercontent->orderid)->getFirstItem();
						if(!($order && $order->getId()))
						{
							try
							{
								$quote = Mage::getModel('sales/quote');

								$this->ProcessQuote($quote, $xml, $store);
							}
							catch(Exception $e)
							{
								$response = $this->getResponse();
								$response->setHeader('Content-Type', 'application/json');
								$response->setBody(Zend_Json::encode(array( 'ack' => 'failed', 'code' => $e->getCode(), 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString())));
								break;
							}
						}

						$connection->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
						$connection->beginTransaction();

						try
						{
							$order = Mage::getModel('sales/order')->getCollection()->addAttributeToFilter('codisto_orderid', $ordercontent->orderid)->getFirstItem();

							if($order && $order->getId())
							{
								$this->ProcessOrderSync($order, $xml, $productsToReindex, $store);
							}
							else
							{
								if(!$quote)
									throw new Exception('Quote not created and order does not exist');

								$this->ProcessOrderCreate($quote, $xml, $productsToReindex, $store);
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
							$response->setHeader('Content-Type', 'application/json');
							$response->setBody(Zend_Json::encode(array( 'ack' => 'failed', 'code' => $e->getCode(), 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString())));

							$connection->rollback();
							break;
						}
					}

					try
					{

						if(count($productsToReindex) > 0)
						{
							Mage::getResourceSingleton('cataloginventory/indexer_stock')->reindexProducts($productsToReindex);
							Mage::getResourceSingleton('catalog/product_indexer_price')->reindexProductIds($productsToReindex);
						}

					}
					catch (Exception $e)
					{

					}
				}
			}
			else
			{
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

	private function ProcessOrderCreate($quote, $xml, $productsToReindex, $store)
	{
		$ordercontent = $xml->entry->content->children('http://api.codisto.com/schemas/2009/');

		$paypaltransactionid = $ordercontent->orderpayments[0]->orderpayment->transactionid;

		$ordertotal = floatval($ordercontent->ordertotal[0]);

		$ebaysalesrecordnumber = $ordercontent->ebaysalesrecordnumber[0];
		if(!$ebaysalesrecordnumber)
			$ebaysalesrecordnumber = '';

		$quoteConverter =  Mage::getModel('sales/convert_quote');

		$quote->reserveOrderId();
		$order = $quoteConverter->addressToOrder($quote->getShippingAddress());
		$order->setBillingAddress($quoteConverter->addressToOrderAddress($quote->getBillingAddress()));
		$order->setShippingAddress($quoteConverter->addressToOrderAddress($quote->getShippingAddress()));
		$order->setPayment($quoteConverter->paymentToOrderPayment($quote->getPayment()));
		$order->setCodistoOrderid($ordercontent->orderid);

		foreach ($quote->getAllItems() as $quoteItem)
		{
			$orderItem = $quoteConverter->itemToOrderItem($quoteItem);
			if ($quoteItem->getParentItem()) {
				$orderItem->setParentItem($order->getItemByQuoteItemId($quoteItem->getParentItem()->getId()));
			}
			$order->addItem($orderItem);

			if($ordercontent->orderstate != 'cancelled')
			{
				$product = $orderItem->getProduct();
				if($product && $product->getId())
				{
					$stockItem = $product->getStockItem();
					if (!$stockItem) {
						$stockItem = Mage::getModel('cataloginventory/stock_item');
						$stockItem->assignProduct($product)
							->setData('stock_id', 1)
							->setData('store_id', $store->getId());
					}

					if($stockItem->canSubtractQty())
					{
						$productsToReindex[$product->getId()] = $product->getId();

						$stockItem->subtractQty($orderItem->getQtyOrdered());
						$stockItem->save();
					}
				}
			}
		}

		$quote->setInventoryProcessed(true);

		$order->setQuote($quote);
		$order->save();

		try
		{
			$order->place();
		}
		catch(Exception $e)
		{

		}

		/* cancelled, processing, captured, inprogress, complete */
		if($ordercontent->orderstate == 'captured') {
			$order->setState(Mage_Sales_Model_Order::STATE_NEW, true);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is pending payment");
		} else if($ordercontent->orderstate == 'cancelled') {
			$order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber has been cancelled");
		} else if($ordercontent->orderstate == 'inprogress' || $ordercontent->orderstate == 'processing') {
			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is in progress");
		} else if ($ordercontent->orderstate == 'complete') {
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_COMPLETE);
			$order->setData('state', Mage_Sales_Model_Order::STATE_COMPLETE);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is complete");
		} else {
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber has been captured");
		}


		$payment = $order->getPayment();

		if($ordercontent->paymentstatus == 'complete')
		{
			$order->setBaseTotalPaid($ordertotal);
			$order->setTotalPaid($ordertotal);
			$order->setBaseTotalDue(0);
			$order->setTotalDue(0);
			$order->setDue(0);

			$payment->setBaseAmountPaid($ordertotal);
		}

		$payment->setParentTransactionId(null)
			->setIsTransactionClosed(1);
		$payment->setMethod('ebay');
		$payment->resetTransactionAdditionalInfo();
		$transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_PAYMENT, null, false, '');
		if($paypaltransactionid)
		{
			$payment->setTransactionId($paypaltransactionid);
		}
		$payment->save();

		$order->save();

		$quote->setIsActive(false)->save();

		if($ordercontent->paymentstatus == 'complete')
		{
			$invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

			if($invoice->getTotalQty())
			{
				$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
				$invoice->register();
			}
			$invoice->save();
		}

		$response = $this->getResponse();

		$response->setHeader('Content-Type', 'application/json');
		$response->setBody(Zend_Json::encode(array( 'ack' => 'ok', 'orderid' => $order->getIncrementId())));
	}

	private function ProcessOrderSync($order, $xml, $productsToReindex, $store)
	{
		$orderstatus = $order->getState();
		$ordercontent = $xml->entry->content->children('http://api.codisto.com/schemas/2009/');

		$ebaysalesrecordnumber = $ordercontent->ebaysalesrecordnumber[0];
		if(!$ebaysalesrecordnumber)
			$ebaysalesrecordnumber = '';

		$currencyCode = $ordercontent->transactcurrency[0];
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
				$freightservice = $orderline->productname[0];
			}
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
		foreach($order->getAllVisibleItems() as $item)
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

		foreach($order->getAllVisibleItems() as $item)
		{
			$item->delete();
		}

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
				$productname = $orderline->productname[0];
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
						$product = Mage::getModel('catalog/product');
					}
				}

				if(!$product)
				{
					$product = Mage::getModel('catalog/product');
					$adjustStock = false;
				}

				$qty = (int)$orderline->quantity[0];
				$subtotalinctax = floatval($orderline->linetotalinctax[0]);
				$subtotal = floatval($orderline->linetotal[0]);

				$totalquantity += $qty;

				$item = Mage::getModel('sales/order_item');
				$item->setStoreId($store->getId());

				$item->setProduct($product);
				$item->setProductId($productid);

				$price = floatval($orderline->price[0]);
				$priceinctax = floatval($orderline->priceinctax[0]);
				$taxamount = $priceinctax - $price;
				$taxpercent = round($priceinctax / $price - 1.0, 2) * 100;
				$weight = floatval($orderline->weight[0]);

				$item->setProductType('simple');
				$item->setSku($productcode);
				$item->setName($productname);
				$item->setIsQtyDecimal(false);
				$item->setNoDiscount(true);
				$item->setQtyOrdered($qty);
				$item->setPrice($price);
				$item->setPriceInclTax($priceinctax);
				$item->setOriginalPrice($priceinctax);
				$item->setBasePrice($price);
				$item->setBasePriceInclTax($priceinctax);
				$item->setBaseOriginalPrice($priceinctax);
				$item->setTaxPercent($taxpercent);
				$item->setTaxAmount($taxamount);
				$item->setDiscountAmount(0);
				$item->setWeight($weight);
				$item->setBaseRowTotal($subtotal);
				$item->setBaseRowTotalInclTax($subtotalinctax);
				$item->setRowTotal($subtotal);
				$item->setRowTotalInclTax($subtotalinctax);
				$item->setWeeeTaxApplied(serialize(array()));

				$order->addItem($item);

				if($ordercontent->orderstate != 'cancelled')
				{
					if($adjustStock)
					{
						$stockItem = $product->getStockItem();
						if (!$stockItem) {
							$stockItem = Mage::getModel('cataloginventory/stock_item');
							$stockItem->assignProduct($product)
								->setData('stock_id', 1)
								->setData('store_id', $store->getId());
						}

						if($stockItem->canSubtractQty())
						{
							$stockReserved = isset($orderlineStockReserved[$productid]) ? $orderlineStockReserved[$productid] : 0;

							$stockMovement = $qty - $stockReserved;

							if($stockMovement > 0)
							{
								$productsToReindex[$product->getId()] = $product->getId();

								$stockItem->subtractQty($stockMovement);
							}
							else if($stockMovement < 0)
							{
								$productsToReindex[$product->getId()] = $product->getId();

								$stockMovement = abs($stockMovement);

								$stockItem->addQty($stockMovement);
							}

							$stockItem->save();
						}
					}
				}
			}
		}

		$order->setTotalQtyOrdered((int)$totalquantity);

		/* States: cancelled, processing, captured, inprogress, complete */
		if($ordercontent->orderstate == 'captured' && ($orderstatus!='pending' && $orderstatus!='new')) {
			$order->setState(Mage_Sales_Model_Order::STATE_NEW, true);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is pending payment");
		}
		if($ordercontent->orderstate == 'cancelled' && $orderstatus!='canceled') {
			$order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber has been cancelled");
		}
		if(($ordercontent->orderstate == 'inprogress' || $ordercontent->orderstate == 'processing') && $orderstatus!='processing') {
			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is in progress");
		}
		if($ordercontent->orderstate == 'complete' && $orderstatus!='complete') {
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_COMPLETE);
			$order->setData('state', Mage_Sales_Model_Order::STATE_COMPLETE);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is complete");
		}

		if(
			($ordercontent->orderstate == 'cancelled' && $orderstatus!='canceled') ||
			($ordercontent->orderstate != 'cancelled' && $orderstatus == 'canceled'))
		{
			foreach($ordercontent->orderlines->orderline as $orderline)
			{
				if($orderline->productcode[0] != 'FREIGHT')
				{
					$catalog = Mage::getModel('catalog/product');
					$prodid = $catalog->getIdBySku((string)$orderline->productcode[0]);
					$product = Mage::getModel('catalog/product')->load($prodid);
					$qty = $orderline->quantity[0];
					$totalquantity += $qty;

					if (!($stockItem = $product->getStockItem())) {
						$stockItem = Mage::getModel('cataloginventory/stock_item');
						$stockItem->assignProduct($product)
							->setData('stock_id', 1)
							->setData('store_id', $store->getId());
					}
					$stockItem = $product->getStockItem();
					$stockData = $stockItem->getData();

					if(isset($stockData['use_config_manage_stock'])) {
						if($stockData['use_config_manage_stock'] != 0) {
							$stockcontrol = Mage::getStoreConfig('cataloginventory/item_options/manage_stock');
						} else {
							$stockcontrol = $stockData['manage_stock'];
						}

						if($stockcontrol !=0) {
							if($ordercontent->orderstate == 'cancelled') {
								$stockItem->addQty(intval($qty));
							} else {
								$stockItem->subtractQty(intval($qty));
							}
						}
					}

					$stockItem->save();
				}
			}
		}

		if($ordercontent->paymentstatus == 'complete')
		{
			$order->setBaseTotalPaid($ordertotal);
			$order->setTotalPaid($ordertotal);
			$order->setBaseTotalDue(0.0);
			$order->setTotalDue(0.0);
			$order->setDue(0.0);

			$payment = $order->getPayment();
			$payment->setMethod('ebay');
			$payment->setParentTransactionId(null)
				->setIsTransactionClosed(1);

			$payment->save();
		}
		else
		{
			$payment = $order->getPayment();
			$payment->setMethod('ebay');
			$payment->save();
		}

		$order->save();

		if(!$order->hasInvoices())
		{
			if($ordercontent->paymentstatus == 'complete' && $order->canInvoice())
			{
				$invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

				if($invoice->getTotalQty())
				{
					$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
					$invoice->register();
				}
				$invoice->save();
			}
		}

		$response = $this->getResponse();
		$response->setHeader('Content-Type', 'application/json');
		$response->setBody(Zend_Json::encode(array( 'ack' => 'ok', 'orderid' => $order->getIncrementId())));
	}

	private function ProcessQuote($quote, $xml, $store)
	{
		$connection = Mage::getSingleton('core/resource')->getConnection('core_write');

		$ordercontent = $xml->entry->content->children('http://api.codisto.com/schemas/2009/');

		$websiteId = $store->getWebsiteId();

		$ebaysalesrecordnumber = $ordercontent->ebaysalesrecordnumber[0];
		if(!$ebaysalesrecordnumber)
			$ebaysalesrecordnumber = '';

		$billing_address = $ordercontent->orderaddresses->orderaddress[0];
		$billing_first_name = $billing_last_name = '';

		if(strpos($billing_address->name, ' ') !== false) {
			$billing_name = explode(' ', $billing_address->name, 2);
			$billing_first_name = $billing_name[0];
			$billing_last_name = $billing_name[1];
		} else {
			$billing_first_name = $billing_address->name;
		}

		$shipping_address = $ordercontent->orderaddresses->orderaddress[1];
		$shipping_first_name = $shipping_last_name = '';

		if(strpos($shipping_address->name, ' ') !== false) {
			$shipping_name = explode(' ', $shipping_address->name, 2);
			$shipping_first_name = $shipping_name[0];
			$shipping_last_name = $shipping_name[1];
		} else {
			$shipping_first_name = $shipping_address->name;
		}

		$email = (string)$billing_address->email;
		if(!$email)
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
			'street' => (string)$billing_address->address1.($shipping_address->address2 ? "\n".$billing_address->address2 : ''),
			'city' => (string)$billing_address->place,
			'postcode' => (string)$billing_address->postalcode,
			'telephone' => (string)$billing_address->phone,
			'fax' => '',
			'country_id' => (string)$billing_address->countrycode,
			'region_id' => $regionsel_id, // id from directory_country_region table
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
			'telephone' => (string)$shipping_address->phone,
			'fax' => '',
			'country_id' => (string)$shipping_address->countrycode,
			'region_id' => $regionsel_id_ship, // id from directory_country_region table
		);

		for($Retry = 0; ; $Retry++)
		{
			try
			{
				$connection->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
				$connection->beginTransaction();

				$customer = Mage::getModel('customer/customer');
				$customer->setWebsiteId($websiteId);
				$customer->setStoreId($store->getId());
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

		$currencyCode = $ordercontent->transactcurrency[0];
		$ordertotal = floatval($ordercontent->ordertotal[0]);
		$ordersubtotal = floatval($ordercontent->ordersubtotal[0]);
		$ordertaxtotal = floatval($ordercontent->ordertaxtotal[0]);

		$ordersubtotal = $store->roundPrice($ordersubtotal);
		$ordersubtotalincltax = $store->roundPrice($ordersubtotal + $ordertaxtotal);
		$ordertotal = $store->roundPrice($ordertotal);

		$quote->setStore($store);
		$quote->setCheckoutMethod(Mage_Checkout_Model_Type_Onepage::METHOD_GUEST);
		$quote->save();

		$quote->assignCustomer($customer);

		$billingAddress = $quote->getBillingAddress();
		$billingAddress->setCustomer($customer);
		$billingAddress->addData($addressBilling);

		$shippingAddress = $quote->getShippingAddress();
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
				$productcode = $orderline->productcode[0];
				if($productcode == null)
					$productcode = '';
				$productname = $orderline->productname[0];
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
					$product = Mage::getModel('catalog/product');
				}

				$qty = (int)$orderline->quantity[0];
				$subtotalinctax = floatval($orderline->linetotalinctax[0]);
				$subtotal = floatval($orderline->linetotal[0]);

				$totalitemcount += 1;
				$totalitemqty += $qty;

				$item = Mage::getModel('sales/quote_item');
				$item->setStoreId($store->getId());
				$item->setQuote($quote);

				$item->setData('product', $product);
				$item->setProductId($productid);
				$item->setProductType('simple');
				$item->setTaxClassId($product->getTaxClassId());
				$item->setBaseCost($product->getBaseCost());
				$item->setIsRecurring(false);

				$price = floatval($orderline->price[0]);
				$priceinctax = floatval($orderline->priceinctax[0]);
				$taxamount = $priceinctax - $price;
				$taxpercent = round($priceinctax / $price - 1.0, 2) * 100;
				$weight = floatval($orderline->weight[0]);

				$item->setSku($productcode);
				$item->setName($orderline->productname[0]);
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
				$item->setTaxAmount($taxamount);
				$item->setBaseTaxAmount($taxamount);
				$item->setRowTotal($subtotal);
				$item->setBaseRowTotal($subtotal);
				$item->setRowTotalWithDiscount($subtotal);
				$item->setRowWeight($weight * $qty);
				$item->setProductType('simple');
				$item->setOriginalCustomPrice($price);
				$item->setPriceInclTax($priceinctax);
				$item->setBasePriceInclTax($priceinctax);
				$item->setRowTotalInclTax($subtotalinctax);
				$item->setBaseRowTotalInclTax($subtotalinctax);
				$item->setWeeeTaxApplied(array());

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
		$quotePayment->importData(array('method' => 'ebay'));

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
		$quote->setCustomerId($customerId);
		$quote->setCustomerGroupId($customerGroupId);
		$quote->setData('trigger_recollect', 0);
		$quote->setTotalsCollectedFlag(true);
		$quote->save();

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

		$freightcode = 'flatrate';
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
		$freightrate = null;

		try {

			$shippingRequest = Mage::getModel('shipping/rate_request');
			$shippingRequest->setAllItems($quote->getAllItems());
			$shippingRequest->setDestCountryId($shippingAddress->getCountryId());
			$shippingRequest->setDestRegionId($shippingAddress->getRegionId());
			$shippingRequest->setDestRegionCode($shippingAddress->getRegionCode());
			$shippingRequest->setDestStreet($shippingAddress->getStreet(Mage_Sales_Model_Quote_Address::DEFAULT_DEST_STREET));
			$shippingRequest->setDestCity($shippingAddress->getCity());
			$shippingRequest->setDestPostcode($shippingAddress->getPostcode());
			$shippingRequest->setPackageValue($quote->getBaseSubtotal());
			$shippingRequest->setPackageValueWithDiscount($quote->getBaseSubtotalWithDiscount());
			$shippingRequest->setPackageWeight($quote->getWeight());
			$shippingRequest->setPackageQty($quote->getItemQty());
			$shippingRequest->setPackagePhysicalValue($quote->getBaseSubtotal());
			$shippingRequest->setFreeMethodWeight(0);
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
				$quoteRate = Mage::getModel('sales/quote_address_rate')
								->importShippingRate($shippingRate);

				if(is_null($freightcost) || (!is_null($rate->getPrice()) && $rate->getPrice() < $freightcost))
				{
					$freightcode = $quoteRate->getCode();
					$freightcarrier = $quoteRate->getCarrier();
					$freightcarriertitle = $quoteRate->getCarrierTitle();
					$freightmethod = $quoteRate->getMethod();
					$freightmethodtitle = $quoteRate->getMethodTitle();
					$freightmethoddescription = $quoteRate->getMethodDescription();

					$freightRate = $quoteRate;
				}

			}
		}
		catch(Exception $e)
		{

		}

		$shippingAddress->addShippingRate($freightRate);
		$shippingAddress->setShippingMethod($freightcode);
		$shippingAddress->setShippingDescription($freightmethodtitle);
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
