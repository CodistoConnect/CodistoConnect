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
		if (!$observer->getEvent()->getBlock()->getIsSecureMode()) {
			syslog(LOG_INFO, "Not secure");
			return;
		} else {
			syslog(LOG_INFO, "Is secure - continuing");
		}

		$shipment = $observer->getEvent()->getShipment();
		$order = $shipment->getOrder();
		$orderid = $order->getIncrementId();

		$remoteUrl = "https://ui.codisto.com/setebayfeedback";
		//$MerchantID = Mage::getStoreConfig('codisto/merchantid');
		//$HostID = Mage::getStoreConfig('codisto/hostid');
		$HostKey = Mage::getStoreConfig('codisto/hostkey');

		$client = new Zend_Http_Client($remoteUrl, array( 'keepalive' => true ));
		if($HostKey)
			$client->setHeaders(array('X-HostKey' => $HostKey));

		$baseurl = Mage::getBaseUrl();
		//$userid = Mage::getSingleton('admin/session')->getUser()->getId();

		syyslog("Sending request to update ebayfeedback");
		$remoteResponse = $client->setRawData('{"type" : "magentoplugin","baseurl" : "' . $baseurl . '", "orderid" :' . $orderid .'}', 'application/json')->request('POST');

		//$data = json_decode($remoteResponse->getRawBody(), true);


		return $this;
	}
}

