<?php
class Codisto_Sync_Controller_Router extends Mage_Core_Controller_Varien_Router_Standard {
 
	public function match(Zend_Controller_Request_Http $request)
    {
        //checking before even try to find out that current module
        //should use this router
        if (!$this->_beforeModuleMatch()) {
            return false;
        }	

		$this->fetchDefault();
		
		$front = $this->getFront();		
		$path = $request->getPathInfo();

		if(0 === strpos($path, '/codisto-sync'))
		{
			$request->setRouteName($this->getRouteByFrontName("codisto-sync"));
		
			if(0 === strpos($path, '/codisto-sync/sync'))
			{
				/*
			
				$controllerClassName = $this->_validateControllerClassName("Codisto_Sync", "Sync");			
				
				$request->setModuleName('codisto-sync')
					->setControllerName('sync')
					->setActionName('checkPlugin');
						
				$request->setDispatched(true);
				
				$controllerInstance = Mage::getControllerInstance("Codisto_Sync_SyncController", $request, $front->getResponse());
				$controllerInstance->dispatch('checkPlugin');	

				return true;
				*/
				
		$this->getConfig();
		$url = 'https://secure.ezimerchant.com/28707/frame/1/' . str_replace('/codisto-sync/sync/', '', $path);

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
/* 		
syslog(1, "HOSTKEY: " . $this->config['HostKey']);
syslog(1, "HOSTKEY: " . print_r($headers, true));
*/
		//WARNING: If you edit the post body, then don't forget to update the content length as it is being set here.
		curl_setopt($ch,CURLOPT_HTTPHEADER ,$headers);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER ,true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); 
		//curl_setopt($ch, CURLOPT_SSL_HOST, FALSE); 
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


		// output the headers verbatim
		foreach(explode("\r\n", $header) as $i => $h)
		{
//			if(stripos($h, "Content-Length") === false``)
				header($h, true);
		}

//		header("Content-Length:" . strlen($body));
//      All posts should be to the current URL only, that way we don't need to worry about Mage::BaseDir().
		echo $body;
		die;
		}
		
			$controllerClassName = $this->_validateControllerClassName("Codisto_Sync", "index");

			$request->setModuleName('codisto-sync')
				->setControllerName('index')
				->setActionName('index');
	
			$request->setDispatched(true);
			
			$controllerInstance = Mage::getControllerInstance("Codisto_Sync_IndexController", $request, $front->getResponse());
			$controllerInstance->dispatch('index');	
	
			return true;
		}
		
		return false;
	
        //checking before even try to find out that current module
        //should use this router
        if (!$this->_beforeModuleMatch()) {
            return false;
        }
/*		
		$this->fetchDefault();
		
		$front = $this->getFront();
		$path = trim($request->getPathInfo(), '/');		
		
		if(0 === strpos($path, 'codisto-sync'))
		{
			if(0 === strpos($path, 'codisto-sync/sync'))
			{
				$controllerInstance = Mage::getControllerInstance("Codisto_Sync_controllers_SyncController", $request, $front->getResponse());
				
				echo $request->getActionName();
				echo "<br/><br/>";
			}
			
			echo $path;
			echo "<br/><br/>";
			
			echo 'hi<br/>';
			print_r($_SERVER);
			
			
			// dispatch action
			$request->setDispatched(true);
			$controllerInstance->dispatch($action);			
			
			return true;
		}
		
		return false;
*/ 
        $this->fetchDefault();
 
        $front = $this->getFront();
        $path = trim($request->getPathInfo(), '/');
 
        if ($path) {
            $p = explode('/', $path);
        } else {
            $p = explode('/', $this->_getDefaultPath());
        }
 
        // get module name
        if ($request->getModuleName()) {
            $module = $request->getModuleName();
        } else {
            if (!empty($p[0])) {
                $module = $p[0];
            } else {
                $module = $this->getFront()->getDefault('module');
                $request->setAlias(Mage_Core_Model_Url_Rewrite::REWRITE_REQUEST_PATH_ALIAS, '');
            }
        }
        if (!$module) {
            if (Mage::app()->getStore()->isAdmin()) {
                $module = 'admin';
            } else {
                return false;
            }
        }
 
        /**
         * Searching router args by module name from route using it as key
         */
 
        $modules = $this->getModuleByFrontName($module);
echo $module;
echo "<br/><br/>";
print_r($modules);
die();
		
        if ($modules === false) {
            return false;
        }
 
        //checkings after we foundout that this router should be used for current module
/*		
        if (!$this->_afterModuleMatch()) {
            return false;
        }
*/

        /**
         * Going through modules to find appropriate controller
         */
        $found = false;
        foreach ($modules as $realModule) {
            $request->setRouteName($this->getRouteByFrontName($module));
 
            // get controller name
            if ($request->getControllerName()) {
                $controller = $request->getControllerName();
            } else {
                if (!empty($p[1])) {
                    $controller = $p[1];
                } else {
                    $controller = $front->getDefault('controller');
                    $request->setAlias(
                        Mage_Core_Model_Url_Rewrite::REWRITE_REQUEST_PATH_ALIAS,
                        ltrim($request->getOriginalPathInfo(), '/')
                    );
                }
            }
 
            // get action name
            if (empty($action)) {
                if ($request->getActionName()) {
                    $action = $request->getActionName();
                } else {
                    $action = !empty($p[2]) ? $p[2] : $front->getDefault('action');
                }
            }
 
            //checking if this place should be secure
            $this->_checkShouldBeSecure($request, '/'.$module.'/'.$controller.'/'.$action);
 
            $controllerClassName = $this->_validateControllerClassName($realModule, $controller);
            if (!$controllerClassName) {
                continue;
            }
 echo $controllerClassName;
 echo "<br/><br/>";
            // instantiate controller class
            $controllerInstance = Mage::getControllerInstance($controllerClassName, $request, $front->getResponse());
 
            if (!$controllerInstance->hasAction($action)) {
                continue;
            }
 
            $found = true;
            break;
        }
 die();
        /**
         * if we did not found any siutibul
         */
        if (!$found) {
            if ($this->_noRouteShouldBeApplied()) {
                $controller = 'index';
                $action = 'noroute';
 
                $controllerClassName = $this->_validateControllerClassName($realModule, $controller);
                if (!$controllerClassName) {
                    return false;
                }
 
                // instantiate controller class
                $controllerInstance = Mage::getControllerInstance($controllerClassName, $request,
                    $front->getResponse());
 
                if (!$controllerInstance->hasAction($action)) {
                    return false;
                }
            } else {
                return false;
            }
        }
 
        // set values only after all the checks are done
        $request->setModuleName($module);
        $request->setControllerName($controller);
        $request->setActionName($action);
        $request->setControllerModule($realModule);
 
        // set parameters from pathinfo
        for ($i = 3, $l = sizeof($p); $i < $l; $i += 2) {
            $request->setParam($p[$i], isset($p[$i+1]) ? urldecode($p[$i+1]) : '');
        }
 
        // dispatch action
        $request->setDispatched(true);
        $controllerInstance->dispatch($action);
 
        return true;
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
