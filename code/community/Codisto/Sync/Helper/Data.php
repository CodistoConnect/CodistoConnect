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


if (!function_exists('hash_equals')) {

	function hash_equals($known_string, $user_string)
	{

		/**
		* This file is part of the hash_equals library
		*
		* For the full copyright and license information, please view the LICENSE
		* file that was distributed with this source code.
		*
		* @copyright Copyright (c) 2013-2014 Rouven Weßling <http://rouvenwessling.de>
		* @license http://opensource.org/licenses/MIT MIT
		*/

		// We jump trough some hoops to match the internals errors as closely as possible
		$argc = func_num_args();
		$params = func_get_args();

		if ($argc < 2) {
			trigger_error("hash_equals() expects at least 2 parameters, {$argc} given", E_USER_WARNING);
			return null;
		}

		if (!is_string($known_string)) {
			trigger_error("hash_equals(): Expected known_string to be a string, " . gettype($known_string) . " given", E_USER_WARNING);
			return false;
		}
		if (!is_string($user_string)) {
			trigger_error("hash_equals(): Expected user_string to be a string, " . gettype($user_string) . " given", E_USER_WARNING);
			return false;
		}

		if (strlen($known_string) !== strlen($user_string)) {
			return false;
		}
		$len = strlen($known_string);
		$result = 0;
		for ($i = 0; $i < $len; $i++) {
			$result |= (ord($known_string[$i]) ^ ord($user_string[$i]));
		}
		// They are only identical strings if $result is exactly 0...
		return 0 === $result;
	}
}


class Codisto_Sync_Helper_Data extends Mage_Core_Helper_Abstract
{
	private $client;
	private $phpInterpreter;

	public function getCodistoVersion()
	{
		return (string) Mage::getConfig()->getNode()->modules->Codisto_Sync->version;
	}

	public function checkHash($Response, $HostKey, $Nonce, $Hash)
	{
		$hashOK = false;

		if(isset($Response)) {

			$r = $HostKey . $Nonce;
			$base = hash('sha256', $r, true);
			$checkHash = base64_encode($base);

			$hashOK = hash_equals($Hash ,$checkHash);
		}

		return $hashOK;

	}

	public function getConfig($storeId)
	{
		$merchantID = Mage::getStoreConfig('codisto/merchantid', $storeId);
		$hostKey = Mage::getStoreConfig('codisto/hostkey', $storeId);

		return isset($merchantID) && $merchantID != ""	&&	isset($hostKey) && $hostKey != "";
	}

	public function getMerchantId($storeId)
	{
		$merchantlist = Zend_Json::decode(Mage::getStoreConfig('codisto/merchantid', $storeId));
		if($merchantlist)
		{
			if(is_array($merchantlist))
			{
				return $merchantlist[0];
			}
			return $merchantlist;
		}
		else
		{
			return 0;
		}
	}

	//Determine if we can create a new merchant. Prevent multiple requests from being able to complete signups
	public function createMerchantwithLock()
	{
		$createMerchant = false;
		$lockFile = Mage::getBaseDir('var') . '/codisto-lock';

		$lockDb = new PDO('sqlite:' . $lockFile);
		$lockDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$lockDb->setAttribute(PDO::ATTR_TIMEOUT, 1);
		$lockDb->exec('BEGIN EXCLUSIVE TRANSACTION');
		$lockDb->exec('CREATE TABLE IF NOT EXISTS Lock (id real NOT NULL)');

		$lockQuery = $lockDb->query('SELECT id FROM Lock UNION SELECT 0 WHERE NOT EXISTS(SELECT 1 FROM Lock)');
		$lockQuery->execute();
		$lockRow = $lockQuery->fetch();
		$timeStamp = $lockRow['id'];

		if($timeStamp + 5000000 < microtime(true))
		{
			$createMerchant = true;

			$lockDb->exec('DELETE FROM Lock');
			$lockDb->exec('INSERT INTO Lock (id) VALUES('. microtime(true) .')');
		}

		$lockDb->exec('COMMIT TRANSACTION');
		$lockDb = null;
		return $createMerchant;
	}

	//Register a new merchant with Codisto
	public function registerMerchant()
	{

		try
		{

			$MerchantID  = null;
			$HostKey = null;

			// Load admin/user so that cookie deserialize will work properly
			Mage::getModel("admin/user");

			// Get the admin session
			$session = Mage::getSingleton('admin/session');

			// Get the user object from the session
			$user = $session->getUser();
			if(!$user)
			{
				$user = Mage::getModel('admin/user')->getCollection()->getFirstItem();
			}

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
				$url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
				$version = Mage::getVersion();
				$storename = Mage::getStoreConfig('general/store_information/name', 0);
				$email = $user->getEmail();
				$codistoversion = $this->getCodistoVersion();
				$ResellerKey = Mage::getConfig()->getNode('codisto/resellerkey');
				if($ResellerKey)
				{
					$ResellerKey = intval(trim((string)$ResellerKey));
				}
				else
				{
					$ResellerKey = '0';
				}

				$curlOptions = array(CURLOPT_TIMEOUT => 60, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0);

				$client = new Zend_Http_Client("https://ui.codisto.com/create", array(
					'adapter' => 'Zend_Http_Client_Adapter_Curl',
					'curloptions' => $curlOptions,
					'keepalive' => true,
					'strict' => false,
					'strictredirects' => true,
					'maxredirects' => 0,
					'timeout' => 30
				));

				$client->setHeaders('Content-Type', 'application/json');
				for($retry = 0; ; $retry++)
				{
					try
					{
						$remoteResponse = $client->setRawData(Zend_Json::encode(array( 'type' => 'magento', 'version' => Mage::getVersion(),
						'url' => $url, 'email' => $email, 'storename' => $storename , 'resellerkey' => $ResellerKey, 'codistoversion' => $codistoversion)))->request('POST');

						if(!$remoteResponse->isSuccessful())
						{
							throw new Exception('Error Creating Account');
						}

						// @codingStandardsIgnoreStart
						$data = Zend_Json::decode($remoteResponse->getRawBody(), true);
						// @codingStandardsIgnoreEnd

						//If the merchantid and hostkey was present in response body
						if(isset($data['merchantid']) && $data['merchantid'] &&	isset($data['hostkey']) && $data['hostkey'])
						{
							$MerchantID = $data['merchantid'];
							$HostKey = $data['hostkey'];

							Mage::getModel("core/config")->saveConfig("codisto/merchantid",  $MerchantID);
							Mage::getModel("core/config")->saveConfig("codisto/hostkey", $HostKey);

							Mage::app()->removeCache('config_store_data');
							Mage::app()->getCacheInstance()->cleanType('config');
							Mage::dispatchEvent('adminhtml_cache_refresh_type', array('type' => 'config'));
							Mage::app()->reinitStores();

							//See if Codisto can reach this server. If not, then attempt to schedule Magento cron entries
							try
							{

								$h = new Zend_Http_Client();
								$h->setConfig(array( 'keepalive' => true, 'maxredirects' => 0, 'timeout' => 20 ));
								$h->setStream();
								$h->setUri('https://ui.codisto.com/'.$MerchantID.'/testendpoint/');
								$h->setHeaders('X-HostKey', $HostKey);
								$testResponse = $h->request('GET');
								$testdata = Zend_Json::decode($testResponse->getRawBody(), true);

								if(isset($testdata['ack']) && $testdata['ack'] == "FAILED") {

									//Endpoint Unreachable - Turn on cron fallback
									$file = new Varien_Io_File();
									$file->open(array('path' => Mage::getBaseDir('var')));
									$file->write('codisto-external-sync-failed', '0');
									$file->close();

								}

							}
							//Codisto can't reach this url so register with Magento cron to pull
							catch (Exception $e) {

								//Check in cron
								$file = new Varien_Io_File();
								$file->open(array('path' => Mage::getBaseDir('var')));
								$file->write('codisto-external-test-failed', '0');
								$file->close();

								Mage::log('Error testing endpoint and writing failed sync file. Message: ' . $e->getMessage() . ' on line: ' . $e->getLine());

							}
						}
					}
					//Attempt to retry register
					catch(Exception $e)
					{
						Mage::log($e->getMessage());
						//Attempt again to register if we
						if($retry < 3)
						{
							usleep(1000000);
							continue;
						}
						//Give in , we can't register at the moment
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
			// remove lock file immediately if any error during account creation
			@unlink($lockFile);
		}
		return $MerchantID;
	}

	public function logExceptionCodisto(Zend_Controller_Request_Http $request, Exception $e, $endpoint)
	{
		try
		{

			$url = ($request->getServer('SERVER_PORT') == '443' ? 'https://' : 'http://') . $request->getServer('HTTP_HOST') . $request->getServer('REQUEST_URI');
			$magentoversion = Mage::getVersion();
			$codistoversion = $this->getCodistoVersion();

			$logEntry = Zend_Json::encode(array(
				'url' => $url,
				'magento_version' => $magentoversion,
				'codisto_version' => $codistoversion,
				'message' => $e->getMessage(),
				'code' => $e->getCode(),
				'file' => $e->getFile(),
				'line' => $e->getLine()));

				Mage::log('CodistoConnect '.$logEntry);

				$client = new Zend_Http_Client($endpoint, array( 'adapter' => 'Zend_Http_Client_Adapter_Curl', 'curloptions' => array(CURLOPT_SSL_VERIFYPEER => false), 'keepalive' => false, 'maxredirects' => 0 ));
				$client->setHeaders('Content-Type', 'application/json');
				$client->setRawData($logEntry);
				$client->request('POST');
		}
		catch(Exception $e2)
		{
			Mage::log("Couldn't notify " . $endpoint . " endpoint of install error. Exception details " . $e->getMessage() . " on line: " . $e->getLine());
		}
	}

	public function eBayReIndex()
	{
		try
		{
			$indexer = Mage::getModel('index/process');
			$indexer->load('codistoebayindex', 'indexer_code')
				->reindexAll()
				->changeStatus(Mage_Index_Model_Process::STATUS_REQUIRE_REINDEX);
		}
		catch (Exception $e)
		{
			Mage::log($e->getMessage());
		}
	}

	private function phpTest($interpreter, $args, $script)
	{
		$process = proc_open('"'.$interpreter.'" '.$args, array(
			array('pipe', 'r'),
			array('pipe', 'w')
		), $pipes);

		@fwrite($pipes[0], $script);
		fclose($pipes[0]);

		$result = @stream_get_contents($pipes[1]);
		if(!$result)
			$result = '';

		fclose($pipes[1]);

		proc_close($process);

		return $result;
	}

	private function phpCheck($interpreter, $requiredVersion, $requiredExtensions)
	{
		if(function_exists('proc_open') &&
			function_exists('proc_close'))
		{
			if(is_array($requiredExtensions))
			{
				$extensionScript = '<?php echo serialize(array('.implode(',',
										array_map(create_function('$ext',
											'return \'\\\'\'.$ext.\'\\\' => extension_loaded(\\\'\'.$ext.\'\\\')\';'),
										$requiredExtensions)).'));';

				$extensionSet = array();
				foreach ($requiredExtensions as $extension)
				{
					$extensionSet[$extension] = 1;
				}
			}
			else
			{
				$extensionScript = '';
				$extensionSet = array();
			}

			$php_version = $this->phpTest($interpreter, '-n', '<?php echo phpversion();');

			if(!preg_match('/^\d+\.\d+\.\d+/', $php_version))
				return '';

			if(version_compare($php_version, $requiredVersion, 'lt'))
				return '';

			if($extensionScript)
			{
				$extensions = $this->phpTest($interpreter, '-n', $extensionScript);
				$extensions = @unserialize($extensions);
				if(!is_array($extensions))
					$extensions = array();

				if($extensionSet == $extensions)
				{
					return '"'.$interpreter.'" -n';
				}
				else
				{
					$php_ini = php_ini_loaded_file();
					if($php_ini)
					{
						$extensions = $this->phpTest($interpreter, '-c "'.$php_ini.'"', $extensionScript);
						$extensions = @unserialize($extensions);
						if(!is_array($extensions))
							$extensions = array();
					}

					if($extensionSet == $extensions)
					{
						return '"'.$interpreter.'" -c "'.$php_ini.'"';
					}
					else
					{
						$extensions = $this->phpTest($interpreter, '', $extensionScript);
						$extensions = @unserialize($extensions);
						if(!is_array($extensions))
							$extensions = array();

						if($extensionSet == $extensions)
						{
							return '"'.$interpreter.'"';
						}
					}
				}
			}
		}
		else
		{
			return '"'.$interpreter.'"';
		}

		return '';
	}

	private function phpPath($requiredExtensions)
	{
		if(isset($this->phpInterpreter))
			return $this->phpInterpreter;

		$interpreterName = array( 'php', 'php5', 'php-cli', 'hhvm' );
		$extension = '';
		if('\\' === DIRECTORY_SEPARATOR)
		{
			$extension = '.exe';
		}

		$dirs = array(PHP_BINDIR);
		if ('\\' === DIRECTORY_SEPARATOR)
		{
			$dirs[] = getenv('SYSTEMDRIVE').'\\xampp\\php\\';
		}

		$open_basedir = ini_get('open_basedir');
		if($open_basedir)
		{
			$basedirs = explode(PATH_SEPARATOR, ini_get('open_basedir'));
			foreach($basedirs as $dir)
			{
				if(@is_dir($dir))
				{
					$dirs[] = $dir;
				}
			}
		}
		else
		{
			$dirs = array_merge(explode(PATH_SEPARATOR, getenv('PATH')), $dirs);
		}

		foreach ($dirs as $dir)
		{
			foreach ($interpreterName as $fileName)
			{
				$file = $dir.DIRECTORY_SEPARATOR.$fileName.$extension;

				if(@is_file($file) && ('\\' === DIRECTORY_SEPARATOR || @is_executable($file)))
				{
					$file = $this->phpCheck($file, '5.0.0', $requiredExtensions);
					if(!$file)
						continue;

					$this->phpInterpreter = $file;

					return $file;
				}
			}
		}

		if(function_exists('shell_exec'))
		{
			foreach ($interpreterName as $fileName)
			{
				$file = shell_exec('which '.$fileName.$extension);
				if($file)
				{
					$file = trim($file);
					if(@is_file($file) && ('\\' === DIRECTORY_SEPARATOR || @is_executable($file)))
					{
						$file = $this->phpCheck($file, '5.0.0', $requiredExtensions);
						if(!$file)
							continue;

						$this->phpInterpreter = $file;

						return $file;
					}
				}
			}
		}

		$this->phpInterpreter = null;

		return null;
	}

	private function runProcessBackground($script, $args, $extensions)
	{
		if(function_exists('proc_open'))
		{
			$interpreter = $this->phpPath($extensions);
			if($interpreter)
			{
				$curl_cainfo = ini_get('curl.cainfo');
				if(!$curl_cainfo && isset($_SERVER['CURL_CA_BUNDLE']))
				{
					$curl_cainfo = $_SERVER['CURL_CA_BUNDLE'];
				}
				if(!$curl_cainfo && isset($_SERVER['SSL_CERT_FILE']))
				{
					$curl_cainfo = $_SERVER['SSL_CERT_FILE'];
				}
				if(!$curl_cainfo && isset($_SERVER['CURL_CA_BUNDLE']))
				{
					$curl_cainfo = $_ENV['CURL_CA_BUNDLE'];
				}
				if(!$curl_cainfo && isset($_ENV['SSL_CERT_FILE']))
				{
					$curl_cainfo = $_ENV['SSL_CERT_FILE'];
				}

				$cmdline = '';
				foreach($args as $arg)
				{
					$cmdline .= '\''.$arg.'\' ';
				}

				if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
				{
					$process = proc_open('start /b '.$interpreter.' "'.$script.'" '.$cmdline, array(), $pipes, Mage::getBaseDir('base'), array( 'CURL_CA_BUNDLE' => $curl_cainfo ));
				}
				else
				{
					$process = proc_open($interpreter.' "'.$script.'" '.$cmdline.' &', array(), $pipes, Mage::getBaseDir('base'), array( 'CURL_CA_BUNDLE' => $curl_cainfo ));
				}

				if(is_resource($process))
				{
					proc_close($process);
					return true;
				}
			}
		}

		return false;
	}

	function runProcess($script, $args, $extensions, $stdin)
	{
		if(function_exists('proc_open')
			&& function_exists('proc_close'))
		{
			$interpreter = $this->phpPath($extensions);
			if($interpreter)
			{
				$curl_cainfo = ini_get('curl.cainfo');
				if(!$curl_cainfo && isset($_SERVER['CURL_CA_BUNDLE']))
				{
					$curl_cainfo = $_SERVER['CURL_CA_BUNDLE'];
				}
				if(!$curl_cainfo && isset($_SERVER['SSL_CERT_FILE']))
				{
					$curl_cainfo = $_SERVER['SSL_CERT_FILE'];
				}
				if(!$curl_cainfo && isset($_ENV['CURL_CA_BUNDLE']))
				{
					$curl_cainfo = $_ENV['CURL_CA_BUNDLE'];
				}
				if(!$curl_cainfo && isset($_ENV['SSL_CERT_FILE']))
				{
					$curl_cainfo = $_ENV['SSL_CERT_FILE'];
				}

				$cmdline = '';
				if(is_array($cmdline))
				{
					foreach($args as $arg)
					{
						$cmdline .= '\''.$arg.'\' ';
					}
				}

				$descriptors = array(
						1 => array('pipe', 'w')
				);

				if(is_string($stdin))
				{
					$descriptors[0] = array('pipe', 'r');
				}

				$process = proc_open($interpreter.' "'.$script.'" '.$cmdline,
							$descriptors, $pipes, Mage::getBaseDir('base'), array( 'CURL_CA_BUNDLE' => $curl_cainfo ));
				if(is_resource($process))
				{
					if(is_string($stdin))
					{
						for($written = 0; $written < strlen($stdin); )
						{
							$writecount = fwrite($pipes[0], substr($stdin, $written));
							if($writecount === false)
								break;

							$written += $writecount;
						}

						fclose($pipes[0]);
					}

					$result = stream_get_contents($pipes[1]);
					fclose($pipes[1]);

					proc_close($process);
					return $result;
				}
			}
		}

		return null;
	}

	public function processCmsContent($content)
	{
		if(strpos($content, '{{') === false)
			return trim($content);

		$result = $this->runProcess('app/code/community/Codisto/Sync/Helper/CmsContent.php', null, array('pdo', 'curl', 'simplexml'), $content);
		if($result != null)
			return $result;

		return Mage::helper('cms')->getBlockTemplateProcessor()->filter(trim($content));
	}

	public function signalOnShutdown($merchants, $msg, $eventtype, $productids)
	{
		try
		{
			if(is_array($productids))
			{
				$syncObject = Mage::getModel('codistosync/sync');

				$storeVisited = array();

				foreach($merchants as $merchant)
				{
					$storeId = $merchant['storeid'];

					if(!isset($storeVisited[$storeId]))
					{
						$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

						if($eventtype == Mage_Index_Model_Event::TYPE_DELETE)
						{
							$syncObject->DeleteProduct($syncDb, $productids, $storeId);
						}
						else
						{
							$syncObject->UpdateProducts($syncDb, $productids, $storeId);
						}

						$storeVisited[$storeId] = 1;
					}
				}
			}

			$backgroundSignal = $this->runProcessBackground('app/code/community/Codisto/Sync/Helper/Signal.php', array(serialize($merchants), $msg), array('pdo', 'curl', 'simplexml'));
			if($backgroundSignal)
				return;

			if(!$this->client)
			{
				$this->client = new Zend_Http_Client();
				$this->client->setConfig(array( 'adapter' => 'Zend_Http_Client_Adapter_Curl', 'curloptions' => array(CURLOPT_TIMEOUT => 4, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0), 'keepalive' => true, 'maxredirects' => 0 ));
				$this->client->setStream();
			}

			foreach($merchants as $merchant)
			{
				try
				{
					$this->client->setUri('https://api.codisto.com/'.$merchant['merchantid']);
					$this->client->setHeaders('X-HostKey', $merchant['hostkey']);
					$this->client->setRawData($msg)->request('POST');
				}
				catch(Exception $e)
				{

				}
			}
		}
		catch(Exception $e)
		{
			Mage::log('error signalling '.$e->getMessage(), null, 'codisto.log');
		}
	}

	public function signal($merchants, $msg, $eventtype = null, $productids = null)
	{
		register_shutdown_function(array($this, 'signalOnShutdown'), $merchants, $msg, $eventtype, $productids);
	}

}
