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
	
		if(0 === strpos($path, '/admin/codisto/'))
		{
			$request->setDispatched(true);
			
			$front = $this->getFront();
			$response = $front->getResponse();

			$MerchantID = Mage::getStoreConfig('codisto/merchantid');
			$HostID = Mage::getStoreConfig('codisto/hostid');
			$HostKey = Mage::getStoreConfig('codisto/hostkey');

			Mage::getSingleton('core/session', array('name'=>'adminhtml'));
				
			if(Mage::getSingleton('admin/session')->isLoggedIn())
			{
				if (preg_match("/\.css|\.js|\.woff|\.ttf|\/images\//i", $path)) {
					
					$remotePath = preg_replace('/^\/admin\/codisto\/ebaytab\/product\/\d+\/?|key\/[a-zA-z0-9]*\//', '', $path);

					$remoteUrl = 'https://ui.codisto.com/' . $MerchantID . '/' . $remotePath;
				
				} else {
				
					$remotePath = preg_replace('/^\/admin\/codisto\/ebaytab\/?|key\/[a-zA-z0-9]*\//', '', $path);
					
					if($MerchantID && $HostID)
					{
						$remoteUrl = 'https://ui.codisto.com/' . $MerchantID . '/frame/' . $HostID . '/' . $remotePath;
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
				
				// set proxied headers
				foreach($this->getAllHeaders() as $k=>$v)
				{
					if(strtolower($k) != "host")
						$client->setHeaders($k, $v);
				}
				
				if($HostKey)
					$client->setHeaders(array('X-HostKey' => $HostKey));

				$requestBody = $request->getRawBody();
				if($requestBody)
					$client->setRawData($requestBody);
	
				$remoteResponse = $client->request($request->getMethod());
				
				if($remoteResponse->getStatus() == 403 &&
					$remoteResponse->getHeader("Content-Type") == "application/json")
				{
					if($request->getQuery('retry'))
					{
						$response->setBody("<html><head></head><body><h1>Unable to Register</h1><p>Sorry, we were unable to register your Codisto account,
						please contact <a href=\"mailto:support@codisto.com\">support@codisto.com</a> and our team will help to resolve the issue</p></body></html>");
					}
					else
					{
						$client->setUri("https://ui.codisto.com/register");
						$baseurl = Mage::getBaseUrl();
						$userid = Mage::getSingleton('admin/session')->getUser()->getId();
						$emailaddress = Mage::getModel('admin/user')->load($userid)->getData('email');
						
						$remoteResponse = $client->setRawData('{"type" : "magentoplugin","baseurl" : "' . $baseurl . '", "emailaddress" : "' . $emailaddress . '"}', 'application/json')->request('POST');
						
						$data = json_decode($remoteResponse->getRawBody(), true);
						$result = $data['result'];
						if(!isset($result['result']['hostid']))
							$result['result']['hostid'] = 1;

						if($result['merchantid'] && $result['result']['hostkey'] && $result['result']['hostid']) {
							Mage::getModel("core/config")->saveConfig("codisto/merchantid", $result['merchantid']);
							Mage::getModel("core/config")->saveConfig("codisto/hostkey", $result['result']['hostkey']);
							Mage::getModel("core/config")->saveConfig("codisto/hostid", $result['result']['hostid']);
							Mage::app()->removeCache('config_store_data');
							Mage::app()->getCacheInstance()->cleanType('config');
							Mage::app()->getStore()->resetConfig();
						}
						
						if($remoteResponse->getStatus() == 200)
						{
							$response->setRedirect($request->getRequestUri() . '?retry=1');
						}
						else
						{
							$response->setBody($remoteResponse->getRawBody());
						}
					}
				}
				else
				{
					// set proxied status and headers
					$response->setHttpResponseCode($remoteResponse->getStatus());
					foreach($remoteResponse->getHeaders() as $k => $v)
					{
						if(strtolower($k) != "server")
							$response->setHeader($k, $v);
					}
	
					// set proxied output
					$response->setBody($remoteResponse->getRawBody());
				}	
	
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

