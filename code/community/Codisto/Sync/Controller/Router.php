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

		if(preg_match('/^\/codisto\//', $path))
		{
			$request->setDispatched(true);

			$front = $this->getFront();
			$response = $front->getResponse();

			$response->clearAllHeaders();

			if(preg_match('/^\/codisto\/ebaytab(?:\/|$)/', $path) && $request->getQuery('productid'))
			{
				$productUrl = Mage::helper('adminhtml')->getUrl('adminhtml/catalog_product/edit', array('id' => $request->getQuery('productid')));

				$response->setRedirect($productUrl);
				$response->sendResponse();

				return true;
			}

			$MerchantID = Mage::getStoreConfig('codisto/merchantid');
			$HostKey = Mage::getStoreConfig('codisto/hostkey');

			// get logged in state
			Mage::getSingleton('core/session', array('name'=>'adminhtml'));
			$loggedIn = Mage::getSingleton('admin/session')->isLoggedIn();

			// unlock session
			if(class_exists('Zend_Session', false) && Zend_Session::isStarted())
				Zend_Session::writeClose();
			if(isset($_SESSION))
				session_write_close();

			if($loggedIn)
			{
				if(!isset($MerchantID) || !isset($HostKey))
				{
					try
					{
						$client = new Zend_Http_Client('https://ui.codisto.com/create', array( 'keepalive' => true, 'maxredirects' => 0 ));
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
									Mage::getModel('core/config')->saveConfig('codisto/merchantid', $data['merchantid']);
									Mage::getModel('core/config')->saveConfig('codisto/hostkey', $data['hostkey']);

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
						$response->setBody('<!DOCTYPE html><html><head></head><body><h1>Unable to Register</h1><p>Sorry, we were unable to register your Codisto account,
						please contact <a href="mailto:support@codisto.com">support@codisto.com</a> and our team will help to resolve the issue</p></body></html>');

						return true;
					}
				}



				if(preg_match('/product\/\d+\/iframe\/\d+\//', $path))
				{
					$tabPath = $request->getBaseUrl().preg_replace('/iframe\/\d+\//', '', $path);

					$response->setHeader('Cache-Control', 'public, max-age=86400', true);
					$response->setHeader('Pragma', 'cache', true);
					$response->setBody('<!DOCTYPE html><html><head><body><iframe id="codisto-control-panel" class="codisto-iframe codisto-product" src="'.$tabPath.'" frameborder="0" onmousewheel=""></iframe></body></html>');

					return true;
				}

				$remotePath = preg_replace('/^\/codisto\/\/?|key\/[a-zA-z0-9]*\//', '', $path);
				if($MerchantID)
				{
					$remoteUrl = 'https://ui.codisto.com/' . $MerchantID . '/' . $remotePath;
				}
				else
				{
					$remoteUrl = 'https://ui.codisto.com/' . $remotePath;
				}

				$querystring = '?';
				foreach($request->getQuery() as $k=>$v) {
					$querystring .= urlencode($k).'='.urlencode($v).'&';
				}

				if($querystring != '?') {
					$remoteUrl.=$querystring;
				}

				$starttime = microtime(true);

				$extensionVersion = (string)Mage::getConfig()->getModuleConfig("Codisto_Sync")->version;

				$curlOptions = array(CURLOPT_TIMEOUT => 10, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0);
				$acceptEncoding = $request->getHeader('Accept-Encoding');
				if(!$acceptEncoding)
					$curlOptions[CURLOPT_ENCODING] = '';

				// proxy request
				$client = new Zend_Http_Client($remoteUrl, array(
																				'adapter' => 'Zend_Http_Client_Adapter_Curl',
																				'curloptions' => $curlOptions,
																				'keepalive' => false,
																				'strict' => false,
																				'strictredirects' => true,
																				'maxredirects' => 0,
																				'timeout' => 10
																			));
				$client->setHeaders('X-Admin-Base-Url', Mage::getBaseURL(Mage_Core_Model_Store::URL_TYPE_LINK).'codisto/ebaytab/');
				$client->setHeaders('X-Codisto-Version', $extensionVersion);

				// set proxied headers
				foreach($this->getAllHeaders() as $k=>$v)
				{
					if(strtolower($k) != 'host')
						$client->setHeaders($k, $v);
				}

				$client->setHeaders(array('X-HostKey' => $HostKey));

				$requestBody = $request->getRawBody();
				if($requestBody)
					$client->setRawData($requestBody);

				for($retry = 0; ; $retry++)
				{
					$remoteResponse = null;

					try
					{
						$remoteResponse = $client->request($request->getMethod());

						if($remoteResponse->isError())
						{
							if((microtime(true) - $starttime < 10.0) &&
									$retry < 3)
							{
								usleep(500000);
								continue;
							}
						}
					}
					catch(Exception $exception)
					{
						if((microtime(true) - $starttime < 10.0) &&
								$retry < 3)
						{
							usleep(500000);
							continue;
						}
					}

					if(!$remoteResponse)
					{
						$response->setHttpResponseCode(500);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setBody('<!DOCTYPE html><html lang="en"><body><h1>Oops</h1><p>Temporary error encountered, please try again</p></body></html>');
						return true;
					}

					// set proxied status and headers
					$response->setHttpResponseCode($remoteResponse->getStatus());
					$response->setHeader('Pragma', '', true);
					$response->setHeader('Cache-Control', '', true);

					$filterHeaders = array('server', 'content-length', 'transfer-encoding', 'date', 'connection');
					if(!$acceptEncoding)
						$filterHeaders[] = 'content-encoding';

					foreach($remoteResponse->getHeaders() as $k => $v)
					{
						if(!in_array(strtolower($k), $filterHeaders, true))
						{
							if(is_array($v))
							{
								$response->setHeader($k, $v[0], true);

								for($i = 1; $i < count($v); $i++)
								{
									$response->setHeader($k, $v[$i]);
								}
							}
							else
							{
								$response->setHeader($k, $v, true);
							}
						}
					}

					if(!$response->isRedirect())
					{
						// set proxied output
						$response->setBody($remoteResponse->getRawBody());
					}

					return true;
				}
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
			} else if ($name == 'CONTENT_TYPE') {
				$headers['Content-Type'] = $value;
			} else if ($name == 'CONTENT_LENGTH') {
				$headers['Content-Length'] = $value;
			}
		}
		if($extra)
			$headers = array_merge($headers, $extra);
		return $headers;
	}
}
