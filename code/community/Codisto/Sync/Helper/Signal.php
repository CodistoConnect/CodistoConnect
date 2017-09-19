<?php

require_once 'app/Mage.php';

Mage::app();

$merchants = unserialize($argv[1]);
$msg = $argv[2];

$curlOptions = array( CURLOPT_TIMEOUT => 20 );

$curlCA = Mage::getBaseDir('var') . '/codisto/codisto.crt';
if(is_file($curlCA)) {
	$curlOptions[CURLOPT_CAINFO] = $curlCA;
}
else if(getenv('CURL_CA_BUNDLE')) {
	$curlOptions[CURLOPT_CAINFO] = getenv['CURL_CA_BUNDLE'];
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
