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
				
syslog(1, 'remoteurl: ' . $remoteUrl);				
				
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
						
						$returnurl = 'http://' . $_SERVER['SERVER_NAME'] . $request->getRequestUri();
						$returnurl = preg_replace('/\/admin\/codisto\/.*/', '/codisto-sync/sync/registerComplete/', $returnurl);

syslog(1, $returnurl);

//						$remotePath = preg_replace('/^\/admin\/codisto\/ebaytab\/?|key\/[a-zA-z0-9]*\//', '', $path);

						$remoteResponse = $client->setRawData('{"returnurl" : "' . $returnurl . '"}', 'application/json')->request('POST');
						
						die('<html><head></head><body><h1>Almost done!</h1><h3><a target="_blank" href="' . $client->getUri()->__toString() . '">Click here to Register</a></h3></body></html>');
						
						$response->setRedirect($client->getUri()->__toString());
						
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

