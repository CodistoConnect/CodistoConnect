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

class Codisto_Sync_IndexController extends Codisto_Sync_Controller_BaseController
{
	protected $_PayPalmethodType = Mage_Paypal_Model_Config::METHOD_WPP_EXPRESS;

	public function calcAction()
	{
		$request = $this->getRequest();
		$response = $this->getResponse();

		$model = Mage::getModel('catalog/product');

		$cart = Mage::getSingleton('checkout/cart');

		$postalcode = $request->getPost('POSTALCODE');
		$division = $request->getPost('DIVISION');
		$countrycode = $request->getPost('COUNTRYCODE');

		if($countrycode == 'AU')
		{
			$pc = $postalcode{0};

			if ($pc == 2 || $pc == 1) {
				$regiontext = "NSW";
			} else if ($pc == 3 || $pc == 8) {
				$regiontext = "VIC";
			} else if ($pc == 4) {
				$regiontext = "QLD";
			} else if ($pc == 5) {
				$regiontext = "SA";
			} else if ($pc == 6) {
				$regiontext = "WA";
			} else if ($pc == 7) {
				$regiontext = "TAS";
			}

			$pc3 = $postalcode{0} . $postalcode{1};
			if ($pc3 == "08" || $pc3 == "09") {
				$regiontext = "NT";
			}

			if ($postalcode == "0872") {
				$regiontext = "SA";
			} else if ($postalcode == "2611" || $postalcode == "3500" || $postalcode == "3585" || $postalcode == "3586" || $postalcode == "3644" || $postalcode == "3707") {
				$regiontext = "NSW";
			} else if ($postalcode == "2620") {
				$regiontext = "ACT";
			}

			if (intval($postalcode) >= 2600 && intval($postalcode) <= 2618) {
				$regiontext = "ACT";
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

				$cart->addProduct($product, $productqty);
			}
		}

		$address = $cart->getQuote()
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

		$response->setBody($output);
	}

	public function indexAction()
	{
		$request = $this->getRequest();
		$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : "";
		$server = $request->getServer();

		if ($this->checkHash($this->config['HostKey'], $server['HTTP_X_NONCE'], $server['HTTP_X_HASH']))
		{
			if($method == 'POST')
			{
				if($content_type == "text/xml")
				{
					$xml = simplexml_load_string(file_get_contents("php://input"));

					$ordercontent = $xml->entry->content->children('http://api.codisto.com/schemas/2009/');

					$order = Mage::getModel('sales/order')->getCollection()->addAttributeToFilter('codisto_orderid', $ordercontent->orderid)->getFirstItem();

					if($order && $order->getId()) {

						$this->ProcessOrderSync($order, $xml);

					} else {

						$this->ProcessOrderCreate($xml);

					}
				}
			}
			else
			{
				include_once Mage::getBaseDir() . '/errors/404.php';
			}
		}
		else
		{
			include_once Mage::getBaseDir() . '/errors/404.php';
		}
	}

	private function ProcessOrderCreate($xml)
	{

		$connection = Mage::getSingleton('core/resource')->getConnection('core_write');
		$connection->beginTransaction();

		try
		{
			$ordercontent = $xml->entry->content->children('http://api.codisto.com/schemas/2009/');
			
			foreach($ordercontent->orderlines->orderline as $orderline)
			{
				if($orderline->productcode[0] != 'FREIGHT') {
					$productid = (int)$orderline->externalreference[0];
					$product = null;
					if($productid)
						$product = Mage::getModel('catalog/product')->load($productid);
					if(!$product) {
						$connection->rollback();
						$response = $this->getResponse();
						$response->setHeader("Content-Type", "application/json");
						$response->setBody(json_encode(array( 'ack' => 'failed', 'message' => 'externalreference not found')));
						die();
					}
				}
			}

			$website = Mage::app()->getWebsite();
			$websiteId = $website->getId();

			$store = Mage::app()->getStore();
			$storeId = $store->getId();

			$currencyCode = $ordercontent->transactcurrency[0];
			$ordertotal = floatval($ordercontent->ordertotal[0]);
			$ordersubtotal = floatval($ordercontent->ordersubtotal[0]);
			$ordertaxtotal = floatval($ordercontent->ordertaxtotal[0]);

			$ordersubtotal = $store->roundPrice($ordersubtotal);
			$ordersubtotalincltax = $store->roundPrice($ordersubtotal + $ordertaxtotal);
			$ordertotal = $store->roundPrice($ordertotal);

			$ebaysalesrecordnumber = $ordercontent->ebaysalesrecordnumber[0];
			if(!$ebaysalesrecordnumber)
				$ebaysalesrecordnumber = '';

			$billing_address = $ordercontent->orderaddresses->orderaddress[0];
			$billing_first_name = $billing_last_name = "";

			if(strpos($billing_address->name, " ") !== false) {
				$billing_name = explode(" ", $billing_address->name, 2);
				$billing_first_name = $billing_name[0];
				$billing_last_name = $billing_name[1];
			} else {
				$billing_first_name = $billing_address->name;
			}

			$shipping_address = $ordercontent->orderaddresses->orderaddress[1];
			$shipping_first_name = $shipping_last_name = "";

			if(strpos($shipping_address->name, " ") !== false) {
				$shipping_name = explode(" ", $shipping_address->name, 2);
				$shipping_first_name = $shipping_name[0];
				$shipping_last_name = $shipping_name[1];
			} else {
				$shipping_first_name = $shipping_address->name;
			}

			$email = (string)$billing_address->email;
			if(!$email)
				$email = "mail@example.com";

			$customer = Mage::getModel('customer/customer');
			$customer->setWebsiteId(Mage::app()->getWebsite()->getId());
			$customer->loadByEmail($email);

			$regionCollection = $this->getRegionCollection($billing_address->countrycode);

			$regionsel_id = 0;
			foreach($regionCollection as $region)
			{
				if(in_array($billing_address->division, array($region['code'], $region['name'])))
				{

					$regionsel_id = $region['region_id'];
				}
			}

			$addressData_billing = array(
				'firstname' => (string)$billing_first_name,
				'lastname' => (string)$billing_last_name,
				'street' => (string)$billing_address->address1.','.$billing_address->address2,
				'city' => (string)$billing_address->place,
				'postcode' => (string)$billing_address->postalcode,
				'telephone' => (string)$billing_address->phone,
				'country_id' => (string)$billing_address->countrycode,
				'region_id' => (string)$regionsel_id, // id from directory_country_region table// id from directory_country_region table
			);

			$regionsel_id_ship = 0;
			foreach($regionCollection as $region)
			{
				if(in_array($shipping_address->division, array($region['code'], $region['name'])))
				{
					$regionsel_id_ship = $region['region_id'];
				}
			}

			$addressData_shipping = array(
				'firstname' => (string)$shipping_first_name,
				'lastname' => (string)$shipping_last_name,
				'street' => (string)$shipping_address->address1.','.$shipping_address->address2,
				'city' => (string)$shipping_address->place,
				'postcode' => (string)$shipping_address->postalcode,
				'telephone' => (string)$shipping_address->phone,
				'country_id' => (string)$shipping_address->countrycode,
				'region_id' => (string)$regionsel_id_ship, // id from directory_country_region table// id from directory_country_region table
			);


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

				$customer->setWebsiteId($websiteId);
				$customer->setStoreId($storeId);
				$customer->setEmail($email);
				$customer->setFirstname((string)$billing_first_name);
				$customer->setLastname((string)$billing_last_name);
				$customer->setPassword('');
				$customer->setData('group_id', $ebayGroup->getId());
				$customer->save();
				$customer->setConfirmation(null);
				$customer->setStatus(1);
				$customer->save();

				$customerId = $customer->getId();

				$customerAddress = Mage::getModel('customer/address');
				$customerAddress->setData($addressData_billing)
					->setCustomerId($customer->getId())
					->setIsDefaultBilling(1)
					->setSaveInAddressBook(1);
				$customerAddress->save();


				$customerAddress->setData($addressData_shipping)
					->setCustomerId($customer->getId())
					->setIsDefaultShipping(1)
					->setSaveInAddressBook(1);
				$customerAddress->save();
			}

			$quote = Mage::getModel('sales/quote');
			$quote->assignCustomer($customer);

			$quote->getBillingAddress()->addData($addressData_billing);
			$quote->getShippingAddress()->addData($addressData_shipping);

			$totalquantity = 0;
			foreach($ordercontent->orderlines->orderline as $orderline)
			{
				if($orderline->productcode[0] != 'FREIGHT')
				{
					$productid = (int)$orderline->externalreference[0];

					$product = Mage::getModel('catalog/product')->load($productid);
					$productcode = $product->getSku();

					$qty = (int)$orderline->quantity[0];
					$subtotalinctax = floatval($orderline->linetotalinctax[0]);
					$subtotal = floatval($orderline->linetotal[0]);

					$totalquantity += $qty;
					
					$item = Mage::getModel('sales/quote_item');
					$item->setProduct($product);
					$item->setSku($productcode);
					$item->setName($orderline->productname[0]);
					$item->setQty($qty);
					$item->setPrice(floatval($orderline->price[0]));
					$item->setPriceInclTax(floatval($orderline->priceinctax[0]));
 					$item->setBasePrice(floatval($orderline->price[0]));
					$item->setBasePriceInclTax(floatval($orderline->priceinctax[0]));
					$item->setOriginalPrice(floatval($orderline->priceinctax[0]));
					$item->setDiscountAmount(0);
					$item->setWeight($orderline->weight[0]);
					$item->setBaseRowTotal($subtotal);
					$item->setBaseRowTotalInclTax($subtotalinctax);
					$item->setRowTotal($subtotal);
					$item->setRowTotalInclTax($subtotalinctax);

					$quote->addItem($item);

					if($ordercontent->orderstate != 'cancelled')
					{
						if($product)
						{
							$stockItem = $product->getStockItem();
							if (!$stockItem) {
								$stockItem = Mage::getModel('cataloginventory/stock_item');
								$stockItem->assignProduct($product)
									->setData('stock_id', 1)
									->setData('store_id', $storeId);
							}

							$stockData = $stockItem->getData();
							
							if(isset($stockData['use_config_manage_stock'])) {
							
								if($stockData['use_config_manage_stock'] != 0) {
									$stockcontrol = Mage::getStoreConfig('cataloginventory/item_options/manage_stock');
								} else {
									$stockcontrol = $stockData['manage_stock'];
								}

								if($stockcontrol !=0) {
									$stockItem->subtractQty(intval($qty));
									$stockItem->save();
								}
							}
						}
					}
				}
			}

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
					$freighttax = (float)$freighttotal - $freighttotalextax;
					$freightservice = $orderline->productname[0];
				}
			}

			$rate = Mage::getModel('sales/quote_address_rate');
			$rate->setCode('flatrate');
			$rate->setCarrier($freightcarrier);
			$rate->setCarrierTitle($freightcarrier);
			$rate->setMethod($freightservice);
			$rate->setMethodTitle($freightservice);
			$rate->setPrice($freighttotal);

			$shippingAddress = $quote->getShippingAddress();
			$shippingAddress->addShippingRate($rate);
			$shippingAddress->setShippingMethod('flatrate_flatrate');
			$shippingAddress->setShippingDescription($freightservice);
			$shippingAddress->setShippingAmountForDiscount(0);

			//$quote->collectTotals();

			$paypalavailable = Mage::getSingleton('paypal/express')->isAvailable();
			if($paypalavailable) {
				$quote->getPayment()->setMethod($this->_PayPalmethodType);
			} else {
				$ebaypaymentmethod = 'ebaypayment';
				$quote->getPayment()->setMethod($ebaypaymentmethod);
			}

			$quote->save();

			$convertquote = Mage::getSingleton('sales/convert_quote');
			$order = $convertquote->toOrder($quote);
			$order->setTotalQtyOrdered((int)$totalquantity);

			$convertquote->addressToOrder($quote->getShippingAddress(), $order);
			$order->setGlobal_currency_code($currencyCode);
			$order->setBase_currency_code($currencyCode);
			$order->setStore_currency_code($currencyCode);
			$order->setOrder_currency_code($currencyCode);
			$order->setBillingAddress($convertquote->addressToOrderAddress($quote->getBillingAddress()));
			$order->setShippingAddress($convertquote->addressToOrderAddress($quote->getShippingAddress()));
			$order->setPayment($convertquote->paymentToOrderPayment($quote->getPayment()));
			$order->setCanShipPartiallyItem(false);

			$order->setCodistoOrderid($ordercontent->orderid);

			$lineidx = 0;
			foreach ($quote->getAllItems() as $item) {

				while(true)
				{
					$orderline = $ordercontent->orderlines->orderline[$lineidx];
					if($orderline->productcode[0] == 'FREIGHT')
					{
						$lineidx++;
						continue;
					}

					break;
				}

				$orderItem = $convertquote->itemToOrderItem($item);
				if ($item->getParentItem()) {
					$orderItem->setParentItem($order->getItemByQuoteItemId($item->getParentItem()->getId()));
				}

				$taxamount = $store->roundPrice(floatval($orderline->linetotalinctax[0]) - floatval($orderline->linetotal[0]));

				$qty = (int)$orderline->quantity[0];
				$subtotalinctax = floatval($orderline->linetotalinctax[0]);
				$subtotal = floatval($orderline->linetotal[0]);

				$orderItem->setBaseTaxAmount($taxamount);
				$orderItem->setTaxAmount($taxamount);
				$orderItem->setTaxPercent(round(floatval($orderline->priceinctax[0]) / floatval($orderline->price[0]) - 1.0, 2) * 100);

				$orderItem->setProduct($product);
				$orderItem->setSku($orderline->productcode[0]);
				$orderItem->setName($orderline->productname[0]);
				$orderItem->setQty($qty);
				$orderItem->setPrice(floatval($orderline->price[0]));
				$orderItem->setPriceInclTax(floatval($orderline->priceinctax[0]));
				$orderItem->setBasePrice(floatval($orderline->price[0]));
				$orderItem->setBasePriceInclTax(floatval($orderline->priceinctax[0]));
				$orderItem->setOriginalPrice(floatval($orderline->priceinctax[0]));
				$orderItem->setDiscountAmount(0);
				$orderItem->setWeight($orderline->weight[0]);
				$orderItem->setBaseRowTotal($subtotal);
				$orderItem->setBaseRowTotalInclTax($subtotalinctax);
				$orderItem->setRowTotal($subtotal);
				$orderItem->setRowTotalInclTax($subtotalinctax);

				$order->addItem($orderItem);

				$lineidx++;
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

			if($ordercontent->paymentstatus == 'complete') {
				$order->setBaseTotalPaid($ordertotal);
				$order->setTotalPaid($ordertotal);
				$order->setBaseTotalDue('0');
				$order->setTotalDue('0');
				$order->setDue('0');
				$payments = $order->getAllPayments();
				foreach($payments as $key=>$payment) {
					$payment->setBaseAmountPaid($ordertotal);
				}
			}

			$payment = $order->getPayment();

			Mage::getSingleton('paypal/info')->importToPayment(null , $payment);
			$paypaltransactionid = $ordercontent->orderpayments[0]->orderpayment->transactionid;

			$order->setShippingMethod('flatrate_flatrate');
			$order->setShippingDescription($freightservice);

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
			
			$order->save();

			if($paypaltransactionid) {
				$payment->setTransactionId($paypaltransactionid);
			}

			$payment->setParentTransactionId(null)
				->setIsTransactionClosed(1);

			$payment->setMethod($this->_PayPalmethodType);
			$transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_PAYMENT, null, false, "");

			$payment->save();

			$quote->setIsActive(false)->save();

			$connection->commit();

			$response = $this->getResponse();

			$response->setHeader("Content-Type", "application/json");
			$response->setBody(json_encode(array( 'productid' => $productid,  'ack' => 'ok', 'orderid' => $order->getIncrementId())));
		}
		catch(Exception $e) {
			$connection->rollback();

			$response = $this->getResponse();
			$response->setHeader("Content-Type", "application/json");
			$response->setBody(json_encode(array( 'ack' => 'failed', 'message' => $e->getMessage())));

		}
	}

	private function ProcessOrderSync($order, $xml)
	{
		try
		{
			$store = Mage::app()->getStore();

			$connection = Mage::getSingleton('core/resource')->getConnection('core_write');
			$connection->beginTransaction();

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

			$order->setShippingMethod('flatrate_flatrate');
			$order->setShippingDescription($freightservice);

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


			/* States: cancelled, processing, captured, inprogress, complete */
			if($ordercontent->orderstate == 'captured' && ($orderstatus!='pending' || $orderstatus!='new')) {
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

			$totalquantity = 0;
			
			if(($ordercontent->orderstate == 'cancelled' && $orderstatus!='canceled') || ($ordercontent->orderstate != 'cancelled' && $orderstatus == 'canceled')) {
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
								->setData('store_id', 1);
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
			
			$order->setTotalQtyOrdered((int)$totalquantity);

			if($ordercontent->paymentstatus == 'complete')
			{
				$order->setBaseTotalPaid($ordertotal);
				$order->setTotalPaid($ordertotal);
				$order->setBaseTotalDue(0.0);
				$order->setTotalDue(0.0);
				$order->setDue(0.0);

				$payment = $order->getPayment();
				$payment->setParentTransactionId(null)
					->setIsTransactionClosed(1);

				$payment->save();
			}

			$order->save();

			$connection->commit();

			$response = $this->getResponse();
			$response->setHeader("Content-Type", "application/json");
			$response->setBody(json_encode(array( 'ack' => 'ok', 'orderid' => $order->getIncrementId())));
		}
		catch(Exception $e) {
			$connection->rollback();

			$response = $this->getResponse();
			$response->setHeader("Content-Type", "application/json");
			$response->setBody(json_encode(array( 'ack' => 'failed', 'message' => $e->getMessage())));
		}
	}

	private function getRegionCollection($countryCode)
	{
		$regionCollection = Mage::getModel('directory/region_api')->items($countryCode);
		return $regionCollection;
	}
}
?>
