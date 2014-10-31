<?php
/**
 * Magento
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
 * @copyright   Copyright (c) 2012 On Technology (http://www.ontech.com.au)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Codisto_Codistoadmin_CodistoadminController extends Mage_Adminhtml_Controller_Action
{
    public function indexAction()
    {
	
// TESTING
	$collection = Mage::getModel('sales/quote')
				->getCollection();
				//->addAttributeToSelect('codisto_orderid');
				//->addFieldToFilter('codisto_order_id', 47);
	foreach($collection as $order) {

		//$order->setCodistoOrderid($order->entity_id.'test');
	
		//$neworder = Mage::getModel('sales/order')->load($order->entity_id);
		//print_r($neworder);
		$codisto = $order->entity_id . ' : ' . $order->getCodistoOrderid();

		$order->save();
		
		echo $codisto . '<br/>';

	}
	die;
// END TESTING
	
        $this->loadLayout();
        $this->renderLayout();
    }

	public function proxyPostAction()
	{
		$this->getConfig();
	    $url = $_REQUEST['proxy_url'];

		//open connection
		$ch = curl_init();

		//set the url, number of POST vars, POST data
		curl_setopt($ch,CURLOPT_URL, $url);
		curl_setopt($ch,CURLOPT_POST, count($_REQUEST));
		curl_setopt($ch,CURLOPT_POSTFIELDS, http_build_query($_POST));
		
		$headers = array();

		foreach($this->getAllHeaders(array("X-HostKey" => $this->config['HostKey'])) as $k=>$v)
		{
			if($k != "Host")
			$headers[] = $k.": ".$v;
		}
		
		curl_setopt($ch,CURLOPT_HTTPHEADER ,$headers);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER ,true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); 
		curl_setopt($ch,CURLOPT_VERBOSE, 1);
		curl_setopt($ch,CURLOPT_HEADER, 1);

		//execute post
		$response = curl_exec($ch);

		//get response data
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$header = substr($response, 0, $header_size);
		$body = substr($response, $header_size);

		print_r($body);
	}

	public function proxyGetAction()
	{ // URL End Point: /magento/index.php/admin/codistoadmin/proxyGet/
		if(strtolower($_SERVER['REQUEST_METHOD']) == "post")
		{
			$this->proxyPostAction();
			die;
		}

		$this->getConfig();
		$url = $_REQUEST['proxy_url'];

		if(substr($url, 0, 4) !== "http")
			$url = base64_decode($url);


		$parts = parse_url($url);
		if($parts && isset($parts['query']))
		{
			parse_str($parts['query'], $res);
			$query =  http_build_query($res);

			$fragment = "";
			if(isset($parts['fragment']))
				$fragment = "#" . $parts['fragment'];

			$url = $parts['scheme'] . "://" . $parts['host'] . $parts['path'] . ($query?"?" .$query : "")  ;
		}


		//open connection
		$ch = curl_init();

		//set the url, number of POST vars, POST data
		curl_setopt($ch,CURLOPT_URL, $url);
		
		$headers = array();

		foreach($this->getAllHeaders(array("X-HostKey" => $this->config['HostKey'])) as $k=>$v)
		{
			if($k != "Host")
			$headers[] = $k.": ".$v;
		}
		
		/*header('Pragma: no-cache');
		header('Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate, proxy-revalidate');
		header('Expires: Tue, 04 Sep 2012 05:32:29 GMT');*/

		//WARNING: If you edit the post body, then don't forget to update the content length as it is being set here.
		curl_setopt($ch,CURLOPT_HTTPHEADER ,$headers);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER ,true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); 
		curl_setopt($ch,CURLOPT_VERBOSE, 1);
		curl_setopt($ch,CURLOPT_HEADER, 1);

		//execute post
		$response = curl_exec($ch);
		
		if($response === false)
		{
			echo 'Oops, there was an error: ' . curl_error($ch);
		}

		//get response data
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$header = substr($response, 0, $header_size);
		$body = substr($response, $header_size);

		if(stripos($header, "Location") !== false)
		{
			preg_match("/Location:(.*)/i", $header, $matches);
			if($matches && isset($matches[1]))
			{
				$location = $matches[1];
				header("Location:/index.php/codisto-sync/sync/proxyGet?proxy_url=" . $matches[1]);
			}
			echo $body;
			die;

		}
		// output the headers verbatim
		foreach(explode("\r\n", $header) as $i => $h)
		{
			//if(stripos($h, "Content-Length") === false``)
				header($h);
		}

		//$body = str_replace("var FormKey = '';", "var FormKey = '" . Mage::getSingleton('core/session')->getFormKey() . "';", $body);
		
		// All posts should be to the current URL only, that way we don't need to worry about Mage::BaseDir().
		echo $body;
	}
	
	private function getAllHeaders($extra = false) {
		foreach ($_SERVER as $name => $value)
		{
			if (substr($name, 0, 5) == 'HTTP_')
			{
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
				$headers[$name] = $value;
			} else if ($name == "CONTENT_TYPE") {
				$headers["Content-Type"] = $value;
			} else if ($name == "CONTENT_LENGTH") {
				$headers["Content-Length"] = $value;
			}
		}
		if($extra)
			$headers = array_merge($headers, $extra);
		return $headers;
	}
	
	private function getConfig()
	{
		$this->config = array(
			"MerchantID" => Mage::getStoreConfig('codisto/merchantid'),
			"ApiKey" => Mage::getStoreConfig('codisto/apikey'),
			"HostKey" => Mage::getStoreConfig('codisto/hostkey'),
			"HostID" => Mage::getStoreConfig('codisto/hostid'),
			"PartnerID" => Mage::getStoreConfig("codisto/partnerid"),
			"PartnerKey" => Mage::getStoreConfig("codisto/partnerkey")
		);

		if (!$this->config['MerchantID'] || $this->config['MerchantID'] == "")
			die("Config Error - Missing MerchantID");
		if (!$this->config['ApiKey'] || $this->config['ApiKey'] == "")
			die("Config Error - Missing ApiKey");
		if (!$this->config['HostKey'] || $this->config['HostKey'] == "")
			die("Config Error - Missing HostKey");
		if (!$this->config['HostID'] || $this->config['HostID'] == "")
			die("Config Error - Missing HostID");
		if (!$this->config['PartnerID'] || $this->config['PartnerID'] == "")
			die("Config Error - Missing PartnerID");
		if (!$this->config['PartnerKey'] || $this->config['PartnerKey'] == "")
			die("Config Error - Missing PartnerKey");
	}	
	
}