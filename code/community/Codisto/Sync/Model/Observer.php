<?php
	
class Codisto_Sync_Model_Observer
{

	public function paymentInfoBlockPrepareSpecificInformation($observer)
	{
		if (!$observer->getEvent()->getBlock()->getIsSecureMode()) {
			return;
		}

		$transport = $observer->getEvent()->getTransport();
		$payment = $observer->getEvent()->getPayment();
		$paypaltransactionid = $payment->getLastTransId();

		if($paypaltransactionid)
			$transport['PayPal TransactionID'] = $paypaltransactionid;

		$ebaysalesrecordnumber =  $payment->getAdditonalInformation('ebaysalesrecordnumber');
		if($ebaysalesrecordnumber)
			$transport['ebay Sales Record Number'] = $ebaysalesrecordnumber;

		return $this;
	}

	public function salesOrderShipmentSaveAfter(Varien_Event_Observer $observer)
	{
		$shipment = $observer->getEvent()->getShipment();
		$order = $shipment->getOrder();
		$orderid = $order->getCodistoOrderid();

		if($orderid) {

			$MerchantID = Mage::getStoreConfig('codisto/merchantid');
			$HostKey = Mage::getStoreConfig('codisto/hostkey');

			$remoteUrl = 'https://api.codisto.com/' . $MerchantID . '/';

			try
			{
				$client = new Zend_Http_Client($remoteUrl, array( 'keepalive' => true, 'maxredirects' => 0 ));
				$client->setHeaders(array('Content-Type' => 'application/json'));
				$client->setHeaders(array('X-HostKey' => $HostKey));
	
				$client->setRawData('{"action" : "setebayfeedback" , "orderid" :' . $orderid .'}', 'application/json')->request('POST');
			}
			catch(Exception $e)
			{
				
			}
		}

		return $this;
	}
	
	public function checkoutAllSubmitAfter($observer)
	{
		if ($observer->getEvent()->hasOrders()) {
			$orders = $observer->getEvent()->getOrders();
		} else {
			$orders = array($observer->getEvent()->getOrder());
		}
		
		$stockItems = array();
		foreach ($orders as $order) {
			foreach ($order->getAllItems() as $orderItem) {
				if ($orderItem->getQtyOrdered()) {
					
					$stockItems[] = $orderItem->getProductId();
					
				}
			}
		}
		
		if (!empty($stockItems)) {
			
			$this->signalStockChange($stockItems);
			
		}
	}
	
	public function stockRevertProductsSale($observer)
	{
		$items = $observer->getEvent()->getItems();
		
		$stockItems = array();
		foreach ($items as $productId => $item) {
			
			$stockItems[] = $productId;

		}
		
		$this->signalStockChange($stockItems);
	}
	

	public function catalogProductImportFinishBefore($observer)
	{
		$stockItems = array();
		$adapter = $observer->getEvent()->getAdapter();

		if ($adapter instanceof Mage_Catalog_Model_Convert_Adapter_Product) {
			$stockItems = $adapter->getAffectedEntityIds();
		} else {

		}
		
		$this->signalStockChange($stockItems);
	}
	
	public function cancelOrderItem($observer)
	{
		$item = $observer->getEvent()->getItem();
		$children = $item->getChildrenItems();
		$qty = $item->getQtyOrdered() - max($item->getQtyShipped(), $item->getQtyInvoiced()) - $item->getQtyCanceled();
		if ($item->getId() && ($productId = $item->getProductId()) && empty($children) && $qty) {
			
			$stockItems = array();
			$stockItems[] = $stockItem->getProductId();
			
			$this->signalStockChange($stockItems);
			
		}
		return $this;
	}
	
	public function addProductTab($observer)
	{
		$block = $observer->getEvent()->getBlock();
		
		if ($block instanceof Mage_Adminhtml_Block_Catalog_Product_Edit_Tabs){
			
			$product = Mage::registry('product');
			
			$type = $product->getTypeId();

			if(in_array($type, array('simple', 'configurable')))
			{
				$entity_id = $product->getId();
				
				$url = Mage::getModel('adminhtml/url')->getUrl('adminhtml/codisto/ebaytab/', array('product' => $entity_id));
				
				$block->addTab('codisto_ebay_tab', array(
					'label' => 'eBay',
					'url'   => $url,
					'class' => 'ajax'
				));
			}
		}
		return $this;
	}
	
	private function signalStockChange($stockItems)
	{
		if(!empty($stockItems))
		{
			$syncDb = Mage::getBaseDir("var") . "/codisto-ebay-sync.db";
	
			$syncObject = Mage::getModel('codistosync/sync');
			
			foreach ($stockItems as $productid)
			{
				$syncObject->UpdateProducts($syncDb, array($productid));
			}
			
			try
			{
				$MerchantID = Mage::getStoreConfig('codisto/merchantid');
				$HostKey = Mage::getStoreConfig('codisto/hostkey');
				
				if(isset($MerchantID) &&
					isset($HostKey))
				{
					$client = new Zend_Http_Client('https://api.codisto.com/'.$MerchantID, array( 'keepalive' => true, 'maxredirects' => 0, 'timeout' => 2 ));
					$client->setHeaders('X-HostKey', $HostKey);
					
					if(count($stockItems) == 1)
						$productids = $stockItems[0];
					else
						$productids = '['.implode(',', $stockItems).']';

					$client->setRawData('action=sync&productid='.$productids)->request('POST');
				}
					
			}
			catch(Exception $e)
			{
				
			}
		}
	}
}

