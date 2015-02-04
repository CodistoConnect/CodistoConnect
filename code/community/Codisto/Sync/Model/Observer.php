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
		$orderid = $order->getIncrementId();

		$MerchantID = Mage::getStoreConfig('codisto/merchantid');
		$HostKey = Mage::getStoreConfig('codisto/hostkey');

		$remoteUrl = 'https://ui.codisto.com/' . $MerchantID . '/setebayfeedback';

		$client = new Zend_Http_Client($remoteUrl, array( 'keepalive' => true ));
		$client->setUri($remoteUrl);
		if($HostKey)
			$client->setHeaders(array('X-HostKey' => $HostKey));

		$baseurl = Mage::getBaseUrl();

		$_SERVER['MERCHANT'] = $MerchantID;
		$client->setRawData('{"action" : "setebayfeedback" , "type" : "magentoplugin","baseurl" : "' . $baseurl . '", "orderid" :' . $orderid .'}', 'application/json')->request('POST');
		
		return $this;
	}
}

