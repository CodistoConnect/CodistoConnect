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
 * @copyright   Copyright (c) 2014 On Technology Pty. Ltd. (http://codisto.com/)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Codisto_Sync_Controller_Router extends Mage_Core_Controller_Varien_Router_Admin {
	
	public function match(Zend_Controller_Request_Http $request)
	{
		$path = $request->getPathInfo();

		if(preg_match("/^\/[a-zA-z0-9-_]+\/codisto\//", $path))
		{
			$request->setDispatched(true);

			
			$front = $this->getFront();
			$response = $front->getResponse();

			$response->clearAllHeaders();

			$MerchantID = Mage::getStoreConfig('codisto/merchantid');
			$HostKey = Mage::getStoreConfig('codisto/hostkey');

			Mage::getSingleton('core/session', array('name'=>'adminhtml'));
				
			if(Mage::getSingleton('admin/session')->isLoggedIn())
			{
				if(!isset($MerchantID) || !isset($HostKey))
				{
					try
					{
						$client = new Zend_Http_Client("https://ui.codisto.com/create", array( 'keepalive' => true, 'maxredirects' => 0 ));
						$client->setHeaders('Content-Type', 'application/json');
						
						for($retry = 0; ; $retry++)
						{
							try
							{
								$url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
								$version = Mage::getVersion();
								$storename = Mage::getStoreConfig('general/store_information/name');
								$email = $user->getEmail();
				
								$remoteResponse = $client->setRawData(json_encode(array( 'type' => 'magento', 'version' => Mage::getVersion(), 'url' => $url, 'email' => $email, 'storename' => $storename )))->request('POST');
								
								if(!$remoteResponse->isSuccessful())
									throw new Exception('Error Creating Account');
				
								$data = json_decode($remoteResponse->getRawBody(), true);
				
								if(isset($data['merchantid']) && $data['merchantid'] &&
									isset($data['hostkey']) && $data['hostkey'])
								{
									Mage::getModel("core/config")->saveConfig("codisto/merchantid", $data['merchantid']);
									Mage::getModel("core/config")->saveConfig("codisto/hostkey", $data['hostkey']);
									
									$MerchantID = $data['merchantid'];
									$HostKey = $data['hostkey'];
								}
							}
							catch(Exception $e)
							{
								if($retry < 3)
								{
									usleep(1000000);
									continue;
								}
								
								throw $e;
							}
							
							break;
						}
					}
					catch(Exception $e)
					{
						$response->setBody("<!DOCTYPE html><html><head></head><body><h1>Unable to Register</h1><p>Sorry, we were unable to register your Codisto account,
						please contact <a href=\"mailto:support@codisto.com\">support@codisto.com</a> and our team will help to resolve the issue</p></body></html>");
						
						return true;
					}
				}
				
				
				
				if(preg_match("/product\/\d+\/iframe\/\d+\//", $path))
				{
					$tabPath = $request->getBaseUrl().preg_replace("/iframe\/\d+\//", '', $path);
					
					$response->setHeader("Cache-Control", "public, max-age=86400", true);
					$response->setHeader("Pragma", "cache", true);
					$response->setBody("<!DOCTYPE html><html><head><body><iframe id='codisto' width=\"100%\" height=\"800\" style=\"border: none; \" src=\"${tabPath}\"></iframe></body></html>");
					
					return true;
				}
				
				if (preg_match("/\.css|\.js|\.woff|\.ttf|\/images\//i", $path)) {
					
					if(preg_match("/product\/\d+\//", $path)) {
						$remotePath = preg_replace('/^\/[a-zA-z0-9-_]+\/codisto\/\w+\/product\/\d+\/?|key\/[a-zA-z0-9]*\//', '', $path);
					} else {
						$remotePath = preg_replace('/^\/[a-zA-z0-9-_]+\/codisto\/\w+\/?|key\/[a-zA-z0-9]*\//', '', $path);
					}
					
					$remoteUrl = 'https://ui.codisto.com/' . $MerchantID . '/' . $remotePath;

				} else {

					if(preg_match("/product\/\d+\//", $path)) {
						$remotePath = preg_replace('/^\/[a-zA-z0-9-_]+\/codisto\/ebaytab\/?|key\/[a-zA-z0-9]*\//', '', $path);
					} else {
						$remotePath = preg_replace('/^\/[a-zA-z0-9-_]+\/codisto\/\/?|key\/[a-zA-z0-9]*\//', '', $path);
					}
					
					if($MerchantID)
					{
						$remoteUrl = 'https://ui.codisto.com/' . $MerchantID . '/' . $remotePath;
					}
					else
					{
						$remoteUrl = 'https://ui.codisto.com/' . $remotePath;
					}
				}

				$querystring = '?';
				foreach($request->getQuery() as $k=>$v) {
					$querystring .= urlencode($k).'='.urlencode($v)."&";
				}

				if($querystring != '?') {
					$remoteUrl.=$querystring;
				}
				
				// proxy request
				$client = new Zend_Http_Client($remoteUrl, array( 'keepalive' => true ));

				$client->setHeaders("X-Admin-Base-Url", Mage::getModel('core/url')->getUrl('adminhtml/codisto/ebaytab/'));

				// set proxied headers
				foreach($this->getAllHeaders() as $k=>$v)
				{
					if(strtolower($k) != "host")
						$client->setHeaders($k, $v);
				}
				
				$client->setHeaders(array('X-HostKey' => $HostKey));

				$requestBody = $request->getRawBody();
				if($requestBody)
					$client->setRawData($requestBody);
	
				$remoteResponse = $client->request($request->getMethod());
				if($remoteResponse->getStatus() != 403)
				{
					// set proxied status and headers
					$response->setHttpResponseCode($remoteResponse->getStatus());
					foreach($remoteResponse->getHeaders() as $k => $v)
					{
						if(!in_array(strtolower($k), array("server", "content-length", "transfer-encoding", "date", "connection"), true))
							$response->setHeader($k, $v, true);
					}
	
					// set proxied output
					$response->setBody($remoteResponse->getRawBody());
					
					return true;
				}
				
				// TODO: hostkey don't match!

				return true;
			}
			else
			{
				include_once Mage::getBaseDir() . '/errors/404.php';
			}
		}

		return false;
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
}
