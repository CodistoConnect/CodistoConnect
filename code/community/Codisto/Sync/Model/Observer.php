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

	public function taxSettingsChangeAfter(Varien_Event_Observer $observer)
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

		$client = new Zend_Http_Client();
		$client->setConfig(array( 'keepalive' => true, 'maxredirects' => 0, 'timeout' => 2 ));
		$client->setStream();

		foreach($merchants as $merchant)
		{
			try
			{
				$client->setUri('https://api.codisto.com/'.$merchant['merchantid']);
				$client->setHeaders('X-HostKey', $merchant['hostkey']);
				$client->setRawData('action=synctax')->request('POST');
			}
			catch(Exception $e)
			{

			}
		}

		return $this;
	}

	public function salesOrderShipmentSaveAfter(Varien_Event_Observer $observer)
	{
		$shipment = $observer->getEvent()->getShipment();
		$order = $shipment->getOrder();
		$orderid = $order->getCodistoOrderid();
		$storeId = $order->getStoreId();

		if($orderid) {

			$HostKey = Mage::getStoreConfig('codisto/hostkey', $storeId);

			$merchantlist = Zend_Json::decode(Mage::getStoreConfig('codisto/merchantid', $storeId));
			if($merchantlist)
			{
				if(!is_array($merchantlist))
					$merchantlist = array($merchantlist);

				$client = new Zend_Http_Client();
				$client->setConfig(array( 'keepalive' => true, 'maxredirects' => 0 ));
				$client->setStream();

				foreach($merchantlist as $MerchantID)
				{
					try
					{

						$client->setUri('https://api.codisto.com/' . $MerchantID . '/');
						$client->setHeaders(array('Content-Type' => 'application/json'));
						$client->setHeaders(array('X-HostKey' => $HostKey));
						$client->setRawData('{"action" : "syncorder" , "orderid" :' . $orderid .'}', 'application/json')->request('POST');

					}
					catch(Exception $e)
					{

					}
				}
			}
		}

		return $this;
	}

	public function salesOrderInvoiceSaveAfter(Varien_Event_Observer $observer)
	{
		$invoice = $observer->getEvent()->getInvoice();
		$order = $invoice->getOrder();
		$orderid = $order->getCodistoOrderid();
		$storeId = $order->getStoreId();

		if($orderid) {

			$HostKey = Mage::getStoreConfig('codisto/hostkey', $storeId);

			$merchantlist = Zend_Json::decode(Mage::getStoreConfig('codisto/merchantid', $storeId));
			if($merchantlist)
			{
				if(!is_array($merchantlist))
					$merchantlist = array($merchantlist);

				$client = new Zend_Http_Client();
				$client->setConfig(array( 'keepalive' => true, 'maxredirects' => 0 ));
				$client->setStream();

				foreach($merchantlist as $MerchantID)
				{
					try
					{
						$client->setUri('https://api.codisto.com/' . $MerchantID . '/');
						$client->setHeaders(array('Content-Type' => 'application/json'));
						$client->setHeaders(array('X-HostKey' => $HostKey));
						$client->setRawData('{"action" : "syncorder" , "orderid" :' . $orderid .'}', 'application/json')->request('POST');
					}
					catch(Exception $e)
					{

					}
				}
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

		return $this;
	}

	public function stockRevertProductsSale($observer)
	{
		$items = $observer->getEvent()->getItems();

		$stockItems = array();
		foreach ($items as $productId => $item) {

			$stockItems[] = $productId;

		}

		$this->signalStockChange($stockItems);

		return $this;
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

		return $this;
	}

	public function cancelOrderItem($observer)
	{
		$item = $observer->getEvent()->getItem();
		$children = $item->getChildrenItems();
		$qty = $item->getQtyOrdered() - max($item->getQtyShipped(), $item->getQtyInvoiced()) - $item->getQtyCanceled();
		if ($item->getId() && ($productId = $item->getProductId()) && empty($children) && $qty) {

			$stockItems = array();
			$stockItems[] = $item->getProductId();

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
				$storeId = $block->getRequest()->getParam('store');
				if(!$storeId)
				 	$storeId = 0;

				$merchantId = '';
				$merchantlist = Mage::getStoreConfig('codisto/merchantid', $storeId);
				if($merchantlist)
				{
					$merchantlist = Zend_Json::decode($merchantlist);
					if(is_array($merchantlist))
						$merchantId = $merchantlist[0];
					else
						$merchantId = $merchantlist;
				}

				$entity_id = $product->getId();

				$url = preg_replace('/\/index\/product\//', '/product/', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab/index', array('product' => $entity_id, 'iframe' => 1)));
				if($merchantId)
					$url .= '?merchantid='.$merchantId.'&storeid='.$storeId;

				$block->addTab('codisto_ebay_tab', array(
					'label' => 'eBay',
					'class' => 'ajax',
					'url'   => $url
				));
			}
		}
		return $this;
	}

	public function addScript($observer)
	{
		$version = (string)Mage::getConfig()->getModuleConfig('Codisto_Sync')->version;

		$merchantlist = Mage::getStoreConfig('codisto/merchantid', 0);
		if($merchantlist)
		{
			$merchantlist = Zend_Json::decode($merchantlist);
			if(is_array($merchantlist))
				$merchant = $merchantlist[0];
			else
				$merchant = $merchantlist;
		}
		else
		{
			$merchant = '';
		}

		$controller = $observer->getAction();
		$layout = $controller->getLayout();
		$block = $layout->createBlock('core/text');
		$block->setText(
		'<script type="text/javascript">
		window.codisto = {
			merchantid : '.$merchant.'
		};
		(function() {
			var s = document.createElement("script");
			s.src = "https://d31wxntiwn0x96.cloudfront.net/connect/" + window.codisto.merchantid + "/js/app/adminhtml.js?v'.$version.'";
			document.getElementsByTagName("HEAD")[0].appendChild(s);
		})();
		</script>');

		$jsBlock = $layout->getBlock('js');
		if($jsBlock)
			$jsBlock->append($block);

		return $this;
	}

	public function cmsStaticBlockSaveAfter($observer)
	{
		if(is_object($observer))
		{
			$eventData = $observer->getEvent()->getData();

			if(isset($eventData['data_object']))
			{
				$dataObject = $eventData['data_object'];

				if(is_subclass_of($dataObject, 'Mage_Core_Model_Abstract'))
				{
					if($dataObject->getResourceName() == 'cms/block')
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

						$client = new Zend_Http_Client();
						$client->setConfig(array( 'keepalive' => true, 'maxredirects' => 0, 'timeout' => 2 ));
						$client->setStream();

						foreach($merchants as $merchant)
						{
							try
							{
								$client->setUri('https://api.codisto.com/'.$merchant['merchantid']);
								$client->setHeaders('X-HostKey', $merchant['hostkey']);
								$client->setRawData('action=syncstaticblock&id='.rawurlencode($dataObject->getId()).'&identifier='.rawurlencode($dataObject->getIdentifier()).'&content='.rawurlencode($dataObject->getContent()))->request('POST');
							}
							catch(Exception $e)
							{

							}
						}
					}
				}
			}
		}

		return $this;
	}

	private function signalStockChange($stockItems)
	{
		if(!empty($stockItems))
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

			$syncObject = Mage::getModel('codistosync/sync');

			$client = new Zend_Http_Client();
			$client->setConfig(array( 'keepalive' => true, 'maxredirects' => 0, 'timeout' => 2 ));
			$client->setStream();

			foreach($merchants as $merchant)
			{
				$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$merchant['storeid'].'.db';

				foreach($stockItems as $productId)
				{
					$syncObject->UpdateProducts($syncDb, array($productId), $merchant['storeid']);
				}

				try
				{
					$client->setUri('https://api.codisto.com/'.$merchant['merchantid']);
					$client->setHeaders('X-HostKey', $merchant['hostkey']);

					if(count($stockItems) == 1)
						$productids = $stockItems[0];
					else
						$productids = '['.implode(',', $stockItems).']';

					$client->setRawData('action=sync&productid='.$productids)->request('POST');
				}
				catch(Exception $e)
				{

				}
			}
		}
	}
}
