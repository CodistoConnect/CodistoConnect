<?php

class Codisto_Sync_Model_Observer
{

	public function paymentInfoBlockPrepareSpecificInformation($observer)
	{
		if ($observer->getEvent()->getBlock()->getIsSecureMode()) {
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

		syslog(LOG_INFO, "Order id is " . $orderid);

		$remoteUrl = "https://ui.codisto.com/setebayfeedback";

		$client = new Zend_Http_Client($remoteUrl, array( 'keepalive' => true ));
		$baseurl = Mage::getBaseUrl();
		//$userid = Mage::getSingleton('admin/session')->getUser()->getId();

		syyslog("Sending request to update ebayfeedback");
		$remoteResponse = $client->setRawData('{"type" : "magentoplugin","baseurl" : "' . $baseurl . '", "orderid" :' . $orderid .'}', 'application/json')->request('POST');

		//$data = json_decode($remoteResponse->getRawBody(), true);


		return $this;
	}
}

