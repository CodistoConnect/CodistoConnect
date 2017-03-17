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

	private function registerMerchant(Zend_Controller_Request_Http $request)
	{
		$merchantID = null;
		$createMerchant = false;

		try
		{
			if(!extension_loaded('pdo'))
			{
				throw new Exception('(PHP Data Objects) please refer to <a target="#blank" href="http://help.codisto.com/article/64-what-is-pdoexception-could-not-find-driver">Codisto help article</a>', 999);
			}

			if(!in_array("sqlite",PDO::getAvailableDrivers(), TRUE))
			{
				throw new PDOException('(sqlite PDO Driver) please refer to <a target="#blank" href="http://help.codisto.com/article/64-what-is-pdoexception-could-not-find-driver">Codisto help article</a>', 999);
			}

			$createMerchant = Mage::helper('codistosync')->createMerchantwithLock(5.0);
		}

		//Something else happened such as PDO related exception
		catch(Exception $e)
		{

			//If competing requests are coming in as the extension is installed the lock above will be held ... don't report this back to Codisto .
			if($e->getCode() != "HY000")
			{
				//Otherwise report  other exception details to Codisto regarding register
				Mage::helper('codistosync')->logExceptionCodisto($e, "https://ui.codisto.com/installed");
			}
			throw $e;
		}

		if($createMerchant)
		{
			$merchantID = Mage::helper('codistosync')->registerMerchant($request);
		}

		if($merchantID)
		{
			$merchantID = Zend_Json::decode($merchantID);
		}

		return $merchantID;
	}


	public function match(Zend_Controller_Request_Http $request)
	{
		$path = $request->getPathInfo();

		if(preg_match('/^\/codisto\//', $path))
		{
			set_time_limit(0);

			@ini_set('zlib.output_compression', 'Off');
			@ini_set('output_buffering', 'Off');
			@ini_set('output_handler', '');

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

			if(isset($_SESSION))
			{
				session_write_close();
				unset($_SESSION);
			}

			$loggedIn = false;

			if($request->getCookie('adminhtml'))
			{
				Mage::unregister('_singleton/admin/session');
				Mage::unregister('_singleton/core/session');

				session_id($request->getCookie('adminhtml'));

				Mage::getSingleton('core/session', array( 'name' => 'adminhtml' ));
				if(Mage::getSingleton('admin/session')->isLoggedIn())
				{
					$loggedIn = true;
				}

				Mage::unregister('_singleton/admin/session');
				Mage::unregister('_singleton/core/session');
			}

			if(!$loggedIn && $request->getCookie('PHPSESSID'))
			{
				Mage::unregister('_singleton/admin/session');
				Mage::unregister('_singleton/core/session');

				session_id($request->getCookie('PHPSESSID'));

				Mage::getSingleton('core/session', array( 'name' => null ));
				if(Mage::getSingleton('admin/session')->isLoggedIn())
				{
					$loggedIn = true;
				}

				Mage::unregister('_singleton/admin/session');
				Mage::unregister('_singleton/core/session');
			}

			// unlock session
			if(class_exists('Zend_Session', false) && Zend_Session::isStarted())
			{
				Zend_Session::writeClose();
			}
			if(isset($_SESSION))
			{
				session_write_close();
			}

			if($loggedIn)
			{
				$storematch = array();

				// get store context from request
				if($request->getQuery('storeid'))
				{
					$storeId = (int)$request->getQuery('storeid');
				}
				else if(preg_match('/^\/codisto\/ebaytab\/(\d+)\/\d+/', $path, $storematch))
				{
					$storeId = (int)$storematch[1];

					$path = preg_replace('/(^\/codisto\/ebaytab\/)(\d+\/?)/', '$1', $path);
				}
				else
				{
					$storeId = (int)$request->getCookie('storeid', '0');
				}

				$Merchants = array();
				$HostKeys = array();

				$stores = Mage::getModel('core/store')->getCollection();
				foreach ($stores as $store)
				{
					$MerchantList = $store->getConfig('codisto/merchantid');

					if($MerchantList)
					{
						$MerchantList = Zend_Json::decode($MerchantList);
						if(is_array($MerchantList))
						{
							foreach($MerchantList as $MerchantID)
							{
								if(is_int($MerchantID))
								{
									array_push($Merchants, $MerchantID);
									$HostKeys[$MerchantID] = $store->getConfig('codisto/hostkey');
								}
							}
						}
						else if(is_int($MerchantList))
						{
							$MerchantID = (int)$MerchantList;

							array_push($Merchants, $MerchantID);
							$HostKeys[$MerchantID] = $store->getConfig('codisto/hostkey');
						}
					}
				}

				$Merchants = array_unique($Merchants);

				$MerchantID = null;

				// register merchant on default admin store if config isn't present
				if(empty($Merchants))
				{
					try
					{
						$MerchantID = $this->registerMerchant($request);
					}
					catch(Exception $e)
					{
						if($e->getCode() == 999)
						{
							$response->setBody('<!DOCTYPE html><html><head></head><body><h1>Unable to Register</h1><p>Sorry, we were unable to register your Codisto account,
							your Magento installation is missing a required Pre-requisite' . $e->getMessage() .
							' or contact <a href="mailto:support@codisto.com">support@codisto.com</a> and our team will help to resolve the issue</p></body></html>');
						}
						else
						{
							$response->setBody('<!DOCTYPE html><html><head></head><body><h1>Unable to Register</h1><p>Sorry, we are currently unable to register your Codisto account.
							In most cases, this is due to your server configuration being unable to make outbound communication to the Codisto servers.</p>
							<p>This is usually easily fixed - please contact <a href="mailto:support@codisto.com">support@codisto.com</a> and our team will help to resolve the issue</p></body></html>');
						}

						return true;
					}

					if($MerchantID == null)
					{
						$response->setBody('<!DOCTYPE html><html><head></head><body><h1>Unable to Register</h1><p>Sorry, we are currently unable to register your Codisto account.
						In most cases, this is due to your server configuration being unable to make outbound communication to the Codisto servers.</p>
						<p>This is usually easily fixed - please contact <a href="mailto:support@codisto.com">support@codisto.com</a> and our team will help to resolve the issue</p></body></html>');

						return true;
					}

					$Merchants[0] = $MerchantID;
					$HostKey = Mage::getStoreConfig('codisto/hostkey');
					$HostKeys[$MerchantID] = $HostKey;
				}

				if(count($Merchants) == 1)
				{
					$MerchantID = $Merchants[0];
					$HostKey = $HostKeys[$MerchantID];

					$path = preg_replace('/(^\/codisto\/[^\/]+\/)(\d+\/?)/', '$1', $path);
				}
				else
				{
					$merchantmatch = array();

					if(preg_match('/^\/codisto\/(?:ebaytab|ebaypayment|ebaysale|ebayuser)\/(\d+)/', $path, $merchantmatch))
					{
						$requestedMerchantID = (int)$merchantmatch[1];

						if(in_array($requestedMerchantID, $Merchants))
						{
							$MerchantID = $requestedMerchantID;
						}
						else
						{
							$MerchantID = $Merchants[0];
						}

						$path = preg_replace('/(^\/codisto\/(?:ebaytab|ebaypayment|ebaysale|ebayuser)\/)(\d+\/?)/', '$1', $path);
					}
					else
					{
						$MerchantID = $Merchants[0];
					}

					$HostKey = $HostKeys[$MerchantID];
				}

				// product page iframe
				if(preg_match('/^\/codisto\/ebaytab\/product\/\d+\/iframe\/\d+\//', $path))
				{
					$tabPort = $request->getServer('SERVER_PORT');
					$tabPort = $tabPort = '' || $tabPort == '80' || $tabPort == '443' ? '' : ':'.$tabPort;
					$tabPath = $request->getServer('REQUEST_URI');
					$tabPath = preg_replace('/iframe\/\d+\//', '', $tabPath);
					$tabURL = $request->getScheme() . '://' . $request->getHttpHost() . $tabPort . $tabPath;

					$response->clearAllHeaders();
					//@codingStandardsIgnoreStart
					if(function_exists('http_response_code'))
						http_response_code(200);
					//@codingStandardsIgnoreEnd
					$response->setHttpResponseCode(200);
					$response->setHeader('Cache-Control', 'public, max-age=86400', true);
					$response->setHeader('Pragma', 'cache', true);
					$response->setBody('<!DOCTYPE html><html><head><body><iframe id="codisto-control-panel" class="codisto-iframe codisto-product" src="'.$tabURL.'" frameborder="0" onmousewheel=""></iframe></body></html>');

					return true;
				}

				$remotePath = preg_replace('/^\/codisto\/\/?|key\/[a-zA-z0-9]*\/?/', '', $path);
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
				$zlibEnabled = strtoupper(ini_get('zlib.output_compression'));

				if(!$acceptEncoding || ($zlibEnabled == 1 || $zlibEnabled == 'ON'))
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
						$response->clearAllHeaders();
						$response->setHttpResponseCode(500);
						$response->setHeader('Pragma', 'no-cache', true);
						$response->setHeader('Cache-Control', 'no-cache, must-revalidate', true);
						$response->setBody('<!DOCTYPE html><html lang="en"><body><h1>Oops</h1><p>Temporary error encountered, please try again</p></body></html>');
						return true;
					}

					// set proxied status and headers
					$response->clearAllHeaders();
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

								$mappedStores = array();

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

									$mappedStores[] = $storeId;
								}

								$stores = Mage::getModel('core/store')->getCollection()->setLoadDefault(true);
								foreach($stores as $store)
								{
									if (!isset($mappedStores[$store->getId()])) {
										$config->deleteConfig('codisto/merchantid', 'stores', $store->getId());
									}
								}

								$config->cleanCache();

								Mage::app()->removeCache('config_store_data');
								Mage::app()->getCacheInstance()->cleanType('config');
								Mage::dispatchEvent('adminhtml_cache_refresh_type', array('type' => 'config'));
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

	private function getAllHeaders($extra = false)
	{

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
		{
			$headers = array_merge($headers, $extra);
		}
		return $headers;
	}
}
