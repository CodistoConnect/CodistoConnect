<?php
  class Codisto_Sync_IndexController extends Mage_Core_Controller_Front_Action
  {
	public function indexAction()
	{
	
		$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : "";
		
		if($method == 'POST')
		{
			if($content_type == "text/xml")
			{
				$xml = simplexml_load_string(file_get_contents("php://input"));
		
				$ordercontent = $xml->entry->content->children('http://api.ezimerchant.com/schemas/2009/');
				
				if($ordercontent &&
					$ordercontent->reason == "OrderCreated")
				{
					$this->ProcessOrderCreate($xml);
				}
				
				else if($ordercontent &&
					$ordercontent->reason == "OrderSync")
				{
					$this->ProcessOrderSync();
				}
				
				else if($ordercontent &&
					$ordercontent->reason == "ProductSync")
				{
					$this->ProductSync();
				}
			}
		}
		else
		{
			 include_once Mage::getBaseDir() . '/errors/404.php';
		}
	}

	
	private function ProcessOrderCreate($xml)
	{
		$website = Mage::app()->getWebsite();
		$websiteId = $website->getId();

		$store = Mage::app()->getStore();
		$storeId = $store->getId();
	
		$ordercontent = $xml->entry->content->children('http://api.ezimerchant.com/schemas/2009/');
		
		$currencyCode = $ordercontent->transactcurrency[0];
		$ebaysalesrecordnumber = $ordercontent->ebaysalesrecordnumber[0];
		$freightcarrier = $ordercontent->freightcarrier[0];
		$freightservice = $ordercontent->freightservice[0];
		

		$billing_address = $ordercontent->orderaddresses->orderaddress[0];
		$billing_name = explode(" ", $billing_address->name, 2);
		$shipping_address = $ordercontent->orderaddresses->orderaddress[1];
		$shipping_name = explode(" ", $shipping_address->name, 2);
		
		$customer = Mage::getModel('customer/customer');
		$customer->setWebsiteId(Mage::app()->getWebsite()->getId());
		$customer->loadByEmail($billing_address->email);

		$regionCollection = $this->getRegionCollection($billing_address->countrycode);

		$regionsel_id = 0;
		foreach($regionCollection as $region) 
		{
				// TODO : deal with name
				if($region['code'] == $billing_address->division)
				{

					$regionsel_id = $region['region_id'];
				}		
		}
		
		$addressData_billing = array(
									'firstname' => $billing_name[0],
									'lastname' => $billing_name[1],
									'street' => $billing_address->address1.','.$billing_address->address2,
									'city' => $billing_address->place,
									'postcode' => $billing_address->postalcode,
									'telephone' => $billing_address->phone,
									'country_id' => $billing_address->countrycode,
									'region_id' => $regionsel_id, // id from directory_country_region table// id from directory_country_region table
							);

		$regionsel_id_ship = 0;
		foreach($regionCollection as $region) 
		{
				// TODO : deal with name
				if($region['code'] == $shipping_address->division)
				{

					$regionsel_id_ship = $region['region_id'];
				}		
		}
				
		$addressData_shipping = array(
				'firstname' => $shipping_name[0],
				'lastname' => $shipping_name[1],
				'street' => $shipping_address->address1.','.$shipping_address->address2,
				'city' => $shipping_address->place,
				'postcode' => $shipping_address->postalcode,
				'telephone' => $shipping_address->phone,
				'country_id' => $shipping_address->countrycode,
				'region_id' => $regionsel_id_ship, // id from directory_country_region table// id from directory_country_region table
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
			$customer->setEmail($billing_address->email);
			$customer->setFirstname($billing_name[0]);
			$customer->setLastname($billing_name[1]);
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
				
		$quote->getPayment()->setMethod('ebaypayment');
		
		$billingAddress  = $quote->getBillingAddress()->addData($addressData_billing);
		$shippingAddress = $quote->getShippingAddress()->addData($addressData_shipping);
		
		foreach($ordercontent->orderlines->orderline as $orderline)
		{
			if($orderline->productcode[0] != 'FREIGHT')
			{
				$product = Mage::getModel('catalog/product');
				$product->setSku($orderline->productcode[0]);
				$product->setName($orderline->productname[0]);
				
				$item = Mage::getModel('sales/quote_item');
				$item->setProduct($product);
				$item->setSku($orderline->productcode[0]);
				$item->setName($orderline->productname[0]);
				$item->setQty($orderline->quantity[0]);
				$item->setPrice($orderline->priceinctax[0]);
				$item->setOriginalPrice($orderline->listpriceinctax[0]);
				$item->setCustomPrice($orderline->priceinctax[0]);
				$item->setOriginalCustomPrice($orderline->listpriceinctax[0]);
			
				$quote->addItem($item);
			}
		}
		
		$freighttotal = 0;
		foreach($ordercontent->orderlines->orderline as $orderline)
		{
			if($orderline->productcode[0] == 'FREIGHT')
			{
				$freighttotal += floatval($orderline->linetotalinctax[0]);
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
		$shippingAddress->setShippingMethod('flatrate');
		$shippingAddress->setShippingDescription($freightservice);
		$shippingAddress->setShippingAmountForDiscount(0);
				
		$quote->collectTotals();
		$quote->save();
		
		
		$convertquote = Mage::getSingleton('sales/convert_quote');
		$order = $convertquote->toOrder($quote);
		$convertquote->addressToOrder($quote->getShippingAddress(), $order);
		$order->setGlobal_currency_code($currencyCode);
		$order->setBase_currency_code($currencyCode);
		$order->setStore_currency_code($currencyCode);
		$order->setOrder_currency_code($currencyCode);
		$order->setBillingAddress($convertquote->addressToOrderAddress($quote->getBillingAddress()));
		$order->setShippingAddress($convertquote->addressToOrderAddress($quote->getShippingAddress()));
		$order->setPayment($convertquote->paymentToOrderPayment($quote->getPayment()));
		$order->setCanShipPartiallyItem(false);
	
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
            
            $orderItem->setBaseTaxAmount($taxamount);
            $orderItem->setTaxAmount($taxamount);
			$orderItem->setTaxPercent(round(floatval($orderline->priceinctax[0]) / floatval($orderline->price[0]) - 1.0, 2) * 100);
			$orderItem->setRowTotal(floatval($orderline->linetotal[0]));
            
            $order->addItem($orderItem);
            
            $lineidx++;
        }
		
		if($ebaysalesrecordnumber)
			$order->addStatusToHistory($order->getStatus(), "Order $ebaysalesrecordnumber received from eBay");
	
		$order->place();
		$order->save();
		
		$quote->setIsActive(false)->save();
		
		echo "OK";
	}
	
	private function ProcessOrderSync()
	{
		
	}
	
	private function getRegionCollection($countryCode)
	{
		$regionCollection = Mage::getModel('directory/region_api')->items($countryCode);
		return $regionCollection;
	}
  }
?>
