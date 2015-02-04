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

		$remoteUrl = "https://ui.codisto.com/orderebayfeedback";

		$client = new Zend_Http_Client($remoteUrl, array( 'keepalive' => true ));

		//send request to

		$baseurl = Mage::getBaseUrl();
		$userid = Mage::getSingleton('admin/session')->getUser()->getId();
		//$emailaddress = Mage::getModel('admin/user')->load($userid)->getData('email');

		//TODO determine a nice endpoint
		//the orderid 
		$remoteResponse = $client->setRawData('{"type" : "magentoplugin","baseurl" : "' . $baseurl . '"}', 'application/json')->request('POST');

		$data = json_decode($remoteResponse->getRawBody(), true);
		$result = $data['result'];
		if(!isset($result['result']['hostid']))
			$result['result']['hostid'] = 1;

		//Do I need to update any state here?

		if($result['merchantid'] && $result['result']['hostkey'] && $result['result']['hostid']) {
			/*
			Mage::getModel("core/config")->saveConfig("codisto/merchantid", $result['merchantid']);
			Mage::getModel("core/config")->saveConfig("codisto/hostkey", $result['result']['hostkey']);
			Mage::getModel("core/config")->saveConfig("codisto/hostid", $result['result']['hostid']);
			Mage::app()->removeCache('config_store_data');
			Mage::app()->getCacheInstance()->cleanType('config');
			Mage::app()->getStore()->resetConfig();
			*/
		}

		/*
		if($remoteResponse->getStatus() == 200)
		{
			$response->setRedirect($request->getRequestUri() . '?retry=1');
		}
		*/

		return $this;
	}
}

