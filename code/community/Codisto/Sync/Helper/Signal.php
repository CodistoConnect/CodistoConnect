<?php

require_once 'app/Mage.php';

Mage::app();

$merchants = unserialize($argv[1]);
$msg = $argv[2];
$eventtype = $argv[3];
$productids = unserialize($argv[4]);

if(is_array($productids)) {
	$syncObject = Mage::getModel('codistosync/sync');
	$helper = Mage::helper('codistosync');

	$storeVisited = array();

	foreach($merchants as $merchant) {
		$storeId = $merchant['storeid'];

		if(!isset($storeVisited[$storeId])) {
			if($storeId == 0) {
				// jump the storeid to first non admin store

				$stores = Mage::getModel('core/store')->getCollection()
											->addFieldToFilter('is_active', array('neq' => 0))
											->addFieldToFilter('store_id', array('gt' => 0))
											->setOrder('store_id', 'ASC');

				if($stores->getSize() == 1) {
					$stores->setPageSize(1)->setCurPage(1);
					$firstStore = $stores->getFirstItem();
					if(is_object($firstStore) && $firstStore->getId()) {
						$storeId = $firstStore->getId();
					}
				}
			}

			$syncDb = $helper->getSyncPath('sync-'.$storeId.'.db');

			if($eventtype == Mage_Index_Model_Event::TYPE_DELETE) {
				$syncObject->DeleteProducts($syncDb, $productids, $storeId);
			} else {
				$syncObject->UpdateProducts($syncDb, $productids, $storeId);
			}

			$storeVisited[$storeId] = 1;
		}
	}
}

$curlOptions = array( CURLOPT_TIMEOUT => 20 );

$curlCA = Mage::getBaseDir('var') . '/codisto/codisto.crt';
if(is_file($curlCA)) {
	$curlOptions[CURLOPT_CAINFO] = $curlCA;
}
else if(getenv('CURL_CA_BUNDLE')) {
	$curlOptions[CURLOPT_CAINFO] = getenv('CURL_CA_BUNDLE');
}

$client = new Zend_Http_Client();
$client->setConfig(array( 'adapter' => 'Zend_Http_Client_Adapter_Curl', 'curloptions' => $curlOptions, 'keepalive' => true, 'maxredirects' => 0 ));
$client->setStream();

foreach($merchants as $merchant) {
    for($Retry = 0; ; $Retry++) {
        try {
            $client->setUri('https://api.codisto.com/'.$merchant['merchantid']);
            $client->setHeaders('X-HostKey', $merchant['hostkey']);
            $client->setRawData($msg)->request('POST');
            break;
        } catch(Exception $e) {
            if($Retry >= 3) {
                Mage::log($e->__toString(), null, 'codisto.log');
                break;
            }

            usleep(100000);
            continue;
        }
    }
}
