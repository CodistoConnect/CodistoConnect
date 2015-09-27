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
 * @copyright   Copyright (c) 2015 On Technology Pty. Ltd. (http://codisto.com/)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Codisto_Sync_Controller_Router extends Mage_Core_Controller_Varien_Router_Admin {

	public function match(Zend_Controller_Request_Http $request)
	{
		$path = $request->getPathInfo();

		if(preg_match('/^\/codisto\//', $path))
		{
			set_time_limit(0);

			$request->setDispatched(true);

			$front = $this->getFront();
			$response = $front->getResponse();

			$response->clearAllHeaders();

			// redirect to product page
			if(preg_match('/^\/codisto\/ebaytab(?:\/|$)/', $path) && $request->getQuery('productid'))
			{
				$productUrl = Mage::helper('adminhtml')->getUrl('adminhtml/catalog_product/edit', array('id' => $request->getQuery('productid')));

				$response->setRedirect($productUrl);
				$response->sendResponse();

				return true;
			}

			// external link handling - e.g. redirect to eBay listing
			if(preg_match('/^\/codisto\/link(?:\/|$)/', $path))
			{
				$section =  $request->getQuery('section');
				$action = $request->getQuery('action');
				$destUrl = Mage::helper('adminhtml')->getUrl('adminhtml/codisto/' . $section) . '?action=' . $action;
				$response->setRedirect($destUrl);
				$response->sendResponse();

				return true;
			}

			$MerchantID = Mage::getStoreConfig('codisto/merchantid', 0);
			$HostKey = Mage::getStoreConfig('codisto/hostkey', 0);
			$ResellerKey = Mage::getConfig()->getNode('codisto/resellerkey');

			Mage::getSingleton('core/session', array('name'=>'adminhtml'));

			// unlock session
			if(class_exists('Zend_Session', false) && Zend_Session::isStarted())
				Zend_Session::writeClose();
			if(isset($_SESSION))
				session_write_close();

			// determine logged in state
			session_id($request->getCookie('adminhtml'));
			session_start('admin');
			if(isset($_SESSION['admin']) &&
				isset($_SESSION['admin']['user']) &&
				is_object($_SESSION['admin']['user']))
			{
					$loggedIn = true;
			}
			else
			{
					$loggedIn = false;
			}
			session_write_close();
			unset($_SESSION);

			if($loggedIn)
			{
				$storematch = array();

				// get store context from request
				if(preg_match('/^\/codisto\/ebaytab\/(\d+)\/\d+/', $path, $storematch))
				{
					$storeId = (int)$storematch[1];

					$path = preg_replace('/(^\/codisto\/ebaytab\/)(\d+\/?)/', '$1', $path);
				}
				else
				{
					$storeId = (int)$request->getCookie('storeid', '0');
				}

				// look up merchantid/hostkey from store
				$MerchantID = Mage::getStoreConfig('codisto/merchantid', $storeId);
				$HostKey = Mage::getStoreConfig('codisto/hostkey', $storeId);

				// register merchant on default admin store if config isn't present
				if(!isset($MerchantID) || !isset($HostKey))
				{
					try
					{
						$createLockFile = Mage::getBaseDir('var') . '/codisto-create-lock';

						$createLockDb = new PDO('sqlite:' . $createLockFile);
						$createLockDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
						$createLockDb->setAttribute(PDO::ATTR_TIMEOUT, 60);
						$createLockDb->exec('BEGIN EXCLUSIVE TRANSACTION');

						Mage::app()->getStore(0)->resetConfig();

						$MerchantID = Mage::getStoreConfig('codisto/merchantid', 0);
						$HostKey = Mage::getStoreConfig('codisto/hostkey', 0);

						if(!isset($MerchantID) || !isset($HostKey))
						{
							$ResellerKey = Mage::getConfig()->getNode('codisto/resellerkey');

							$client = new Zend_Http_Client('https://ui.codisto.com/create', array( 'keepalive' => true, 'maxredirects' => 0 ));
							$client->setHeaders('Content-Type', 'application/json');

							for($retry = 0; ; $retry++)
							{
								try
								{
									Mage::getModel("admin/user");
									$session = Mage::getSingleton('admin/session');

									$user = $session->getUser();
									if(!$user)
									{
										$user = Mage::getModel('admin/user')->getCollection()->getFirstItem();
									}

									$url = ($request->getServer('SERVER_PORT') == '443' ? 'https://' : 'http://') . $request->getServer('HTTP_HOST') . substr($path, 0, strpos($path, 'codisto'));
									$version = Mage::getVersion();
									$storename = Mage::getStoreConfig('general/store_information/name', 0);
									$email = $user->getEmail();

									$remoteResponse = $client->setRawData(Zend_Json::encode(array( 'type' => 'magento', 'version' => Mage::getVersion(),
									'url' => $url, 'email' => $email, 'storename' => $storename , 'resellerkey' => $ResellerKey)))->request('POST');

									if(!$remoteResponse->isSuccessful())
										throw new Exception('Error Creating Account');

									$data = Zend_Json::decode($remoteResponse->getRawBody(), true);

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

						$createLockDb->exec('COMMIT TRANSACTION');
						$createLockDb = null;
					}
					catch(Exception $e)
					{
						$response->setBody('<!DOCTYPE html><html><head></head><body><h1>Unable to Register</h1><p>Sorry, we were unable to register your Codisto account,
						please contact <a href="mailto:support@codisto.com">support@codisto.com</a> and our team will help to resolve the issue</p></body></html>');

						return true;
					}
				}

				// get actual merchant id value from request context
				$MerchantID = Zend_Json::decode($MerchantID);
				if(is_array($MerchantID))
				{
					$merchantmatch = array();

					if(preg_match('/^\/codisto\/ebaytab\/(\d+)/', $path, $merchantmatch))
					{
						$requestedMerchantID = (int)$merchantmatch[1];

						if(in_array($requestedMerchantID, $MerchantID))
						{
							$MerchantID = $requestedMerchantID;
						}
						else
						{
							$MerchantID = $MerchantID[0];
						}

						$path = preg_replace('/(^\/codisto\/ebaytab\/)(\d+\/?)/', '$1', $path);
					}
					else
					{
						$MerchantID = $MerchantID[0];
					}
				}
				else
				{
					$path = preg_replace('/(^\/codisto\/ebaytab\/)(\d+\/?)/', '$1', $path);
				}

				// product page iframe
				if(preg_match('/^\/codisto\/ebaytab\/product\/\d+\/iframe\/\d+\//', $path))
				{
					$tabPort = $request->getServer('SERVER_PORT');
					$tabPort = $tabPort = '' || $tabPort == '80' || $tabPort == '443' ? '' : ':'.$tabPort;
					$tabPath = $request->getServer('REQUEST_URI');
					$tabPath = preg_replace('/iframe\/\d+\//', '', $tabPath);
					$tabURL = $request->getScheme() . '://' . $request->getHttpHost() . $tabPort . $tabPath;

					$response->setHeader('Cache-Control', 'public, max-age=86400', true);
					$response->setHeader('Pragma', 'cache', true);
					$response->setBody('<!DOCTYPE html><html><head><body><iframe id="codisto-control-panel" class="codisto-iframe codisto-product" src="'.$tabURL.'" frameborder="0" onmousewheel=""></iframe></body></html>');

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

					$querystring .= urlencode($k);
					if($v)
						$querystring .= '='.urlencode($v);
					$querystring .= '&';

				}
				$querystring = rtrim(rtrim($querystring, '&'), '?');

				$remoteUrl.=$querystring;

				$starttime = microtime(true);

				$extensionVersion = (string)Mage::getConfig()->getModuleConfig('Codisto_Sync')->version;

				$curlOptions = array(CURLOPT_TIMEOUT => 60, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0);
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

				$adminBasePort = $request->getServer('SERVER_PORT');
				$adminBasePort = $adminBasePort = '' || $adminBasePort == '80' || $adminBasePort == '443' ? '' : ':'.$adminBasePort;
				$adminBasePath = $request->getServer('REQUEST_URI');
				$adminBasePath = substr($adminBasePath, 0, strpos($adminBasePath, '/codisto/'));
				$adminBaseURL = $request->getScheme() . '://' . $request->getHttpHost() . $adminBasePort . $adminBasePath . '/codisto/ebaytab/'.$storeId.'/'.$MerchantID.'/';

				$client->setHeaders('X-Admin-Base-Url', $adminBaseURL);
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

					$filterHeaders = array('server', 'content-length', 'transfer-encoding', 'date', 'connection', 'x-storeviewmap');
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
						else
						{
							if(strtolower($k) == 'x-storeviewmap')
							{
								$config = Mage::getConfig();

								$storeViewMapping = Zend_Json::decode($v);

								foreach($storeViewMapping as $mapping)
								{
									$storeId = $mapping['storeid'];
									$merchantList = $mapping['merchants'];

									if($storeId == 0)
									{
										$config->saveConfig('codisto/merchantid', $merchantList);
									}
									else
									{
										$config->saveConfig('codisto/merchantid', $merchantList, 'stores', $storeId);
									}
								}

								$config->cleanCache();

								Mage::app()->removeCache('config_store_data');
								Mage::app()->getCacheInstance()->cleanType('config');
								Mage::app()->reinitStores();
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

				return true;
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
