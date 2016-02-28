<?php

require_once 'app/Mage.php';

$merchants = unserialize($argv[1]);
$msg = $argv[2];

$curlOptions = array( CURLOPT_TIMEOUT => 10, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0 );

if(isset($_ENV['CURL_CA_BUNDLE']) && $_ENV['CURL_CA_BUNDLE'])
{
	$curlOptions[CURLOPT_CAINFO] = $_ENV['CURL_CA_BUNDLE'];
}

$client = new Zend_Http_Client();
$client->setConfig(array( 'adapter' => 'Zend_Http_Client_Adapter_Curl', 'curloptions' => $curlOptions, 'keepalive' => true, 'maxredirects' => 0 ));
$client->setStream();

foreach($merchants as $merchant)
{
	for($Retry = 0; ; $Retry++)
	{
		try
		{
			$client->setUri('https://api.codisto.com/'.$merchant['merchantid']);
			$client->setHeaders('X-HostKey', $merchant['hostkey']);
			$client->setRawData($msg)->request('POST');
			break;
		}
		catch(Exception $e)
		{
			if($Retry > 3)
			{
				Mage::logException($e);
				break;
			}

			usleep(100000);
			continue;
		}
	}
}

?>
