<?php
/**
* Codisto Sales Channels Sync Extension
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
    private $caCertRequested = false;

    public function getCodistoVersion()
    {
        return (string)Mage::getConfig()->getNode()->modules->Codisto_Sync->version;
    }

    public function getTriggerMode()
    {
        return (string)Mage::getConfig()->getNode()->modules->Codisto_Sync->trigger_mode != 'false';
    }

    public function checkRequestHash($key, $server)
    {
        if(!isset($server['HTTP_X_NONCE'])) {
            return false;
        }

        if(!isset($server['HTTP_X_HASH'])) {
            return false;
        }

        $nonce = $server['HTTP_X_NONCE'];
        $hash = $server['HTTP_X_HASH'];

        try {
            $nonceDbPath = $this->getSyncPath('nonce.db');

            $nonceDb = new PDO('sqlite:' . $nonceDbPath);
            $nonceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $nonceDb->exec('CREATE TABLE IF NOT EXISTS nonce (value text NOT NULL PRIMARY KEY)');
            $qry = $nonceDb->prepare('INSERT OR IGNORE INTO nonce (value) VALUES(?)');
            $qry->execute( array( $nonce ) );
            if($qry->rowCount() !== 1) {
                return false;
            }
        } catch(Exception $e) {
            if(property_exists($e, 'errorInfo') &&
                    $e->errorInfo[0] == 'HY000' &&
                    $e->errorInfo[1] == 8 &&
                    $e->errorInfo[2] == 'attempt to write a readonly database') {
                if(file_exists($nonceDbPath)) {
                    unlink($nonceDbPath);
                }
            } else if(property_exists($e, 'errorInfo') &&
                    $e->errorInfo[0] == 'HY000' &&
                    $e->errorInfo[1] == 11 &&
                    $e->errorInfo[2] == 'database disk image is malformed') {
                if(file_exists($nonceDbPath)) {
                    unlink($nonceDbPath);
                }
            } else {
                $this->logExceptionCodisto($e, 'https://ui.codisto.com/installed');
            }
        }

        return $this->checkHash($key, $nonce, $hash);
    }

    private function checkHash($Key, $Nonce, $Hash)
    {
        $Sig = base64_encode(hash('sha256', $Key . $Nonce, true));

        return hash_equals($Hash, $Sig);
    }

    public function getConfig($storeId)
    {
        $merchantID = Mage::getStoreConfig('codisto/merchantid', $storeId);
        $hostKey = Mage::getStoreConfig('codisto/hostkey', $storeId);

        return isset($merchantID) && $merchantID != ""
                && isset($hostKey) && $hostKey != "";
    }

    public function getMerchantId($storeId)
    {
        $merchantlist = Zend_Json::decode(Mage::getStoreConfig('codisto/merchantid', $storeId));
        if($merchantlist) {
            if(is_array($merchantlist)) {
                return $merchantlist[0];
            }
            return $merchantlist;
        } else {
            return 0;
        }
    }

    //Register a new merchant with Codisto
    public function registerMerchant($emailaddress, $countrycode)
    {

        try
        {
            $MerchantID  = null;
            $HostKey = null;

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
                $codistoversion = $this->getCodistoVersion();
                $ResellerKey = Mage::getConfig()->getNode('codisto/resellerkey');
                if($ResellerKey) {
                    $ResellerKey = intval(trim((string)$ResellerKey));
                } else {
                    $ResellerKey = '0';
                }

                $curlOptions = array(CURLOPT_TIMEOUT => 60, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0);

                $curlCA = Mage::getBaseDir('var') . '/codisto/codisto.crt';
                if(is_file($curlCA)) {
                    $curlOptions[CURLOPT_CAINFO] = $curlCA;
                }

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
                        $remoteResponse = $client->setRawData(
                            Zend_Json::encode(
                                array(
                                    'type' => 'magento',
                                    'version' => $version,
                                    'url' => $url,
                                    'email' => $emailaddress,
                                    'country' => $countrycode,
                                    'storename' => $storename ,
                                    'resellerkey' => $ResellerKey,
                                    'codistoversion' => $codistoversion
                                )
                            )
                        )->request('POST');

                        if(!$remoteResponse->isSuccessful())
                        {
                            throw new Exception('Error Creating Account');
                        }

                        // @codingStandardsIgnoreStart
                        $data = Zend_Json::decode($remoteResponse->getRawBody(), true);
                        // @codingStandardsIgnoreEnd

                        //If the merchantid and hostkey was present in response body
                        if(isset($data['merchantid'])
                            && $data['merchantid']
                            && isset($data['hostkey'])
                            && $data['hostkey']) {

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
                        if(preg_match('/server\s+certificate\s+verification\s+failed/', $e->getMessage())) {

                            if(!array_key_exists(CURLOPT_CAINFO, $curlOptions)) {
                                $this->getCACert();

                                if(is_file($curlCA)) {
                                    $curlOptions[CURLOPT_CAINFO] = $curlCA;
                                    $client->getAdapter()->setCurlOptions($curlOptions);
                                }
                            }

                        }

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

    public function canSyncIncrementally($syncDbPath, $storeId)
    {
        if(!$this->getTriggerMode()) {
            return false;
        }

        try {
            $adapter = Mage::getModel('core/resource')->getConnection(Mage_Core_Model_Resource::DEFAULT_WRITE_RESOURCE);

            $tablePrefix = Mage::getConfig()->getTablePrefix();

            // change tables
            $changeTableDefs = array(
                'codisto_product_change' => 'CREATE TABLE `'.$tablePrefix.'codisto_product_change` (product_id int(10) unsigned NOT NULL PRIMARY KEY, stamp datetime NOT NULL, event datetime NOT NULL DEFAULT CURRENT_TIMESTAMP)',
                'codisto_order_change' => 'CREATE TABLE `'.$tablePrefix.'codisto_order_change` (order_id int(10) unsigned NOT NULL PRIMARY KEY, stamp datetime NOT NULL, event datetime NOT NULL DEFAULT CURRENT_TIMESTAMP)',
                'codisto_category_change' => 'CREATE TABLE `'.$tablePrefix.'codisto_category_change` (category_id int(10) unsigned NOT NULL PRIMARY KEY, stamp datetime NOT NULL, event datetime NOT NULL DEFAULT CURRENT_TIMESTAMP)'
            );

            $changeTablesExist = true;

            $changeTables = $adapter->fetchCol('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE \''.$tablePrefix.'codisto_%_change\'');
            if(is_array($changeTables)) {
                $changeTables = array_flip( $changeTables );

                foreach($changeTableDefs as $table => $createStatement) {
                    if(!isset($changeTables[$tablePrefix.$table])) {
                        $adapter->query($changeTableDefs[$table]);

                        $changeTablesExist = false;
                    } else {

                        try {
                            $adapter->query('ALTER TABLE `'.$tablePrefix.$table.'` ADD COLUMN event datetime NOT NULL DEFAULT CURRENT_TIMESTAMP');

                        } catch(Exception $e) {

                        }

                    }
                }
            }

            // trigger management
            $stdCodistoProductChangeStmt = 'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_product_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_product_change` SET product_id = NEW.entity_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE product_id = product_id, stamp = UTC_TIMESTAMP(); END IF;';
            $stdCodistoProductDeleteStmt = 'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_product_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_product_change` SET product_id = OLD.entity_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE product_id = product_id, stamp = UTC_TIMESTAMP(); END IF;';
            $stdCodistoCategoryChangeStmt = 'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_category_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_category_change` SET category_id = NEW.entity_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE category_id = category_id, stamp = UTC_TIMESTAMP(); END IF;';
            $stdCodistoCategoryDeleteStmt = 'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_category_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_category_change` SET category_id = OLD.entity_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE category_id = category_id, stamp = UTC_TIMESTAMP(); END IF;';

            $triggerStaticRules = array(
                                'catalog_product_entity' => array( $stdCodistoProductChangeStmt, $stdCodistoProductChangeStmt, $stdCodistoProductDeleteStmt ),
                                'catalog_product_entity_datetime' => array( $stdCodistoProductChangeStmt, $stdCodistoProductChangeStmt, $stdCodistoProductDeleteStmt ),
                                'catalog_product_entity_decimal' => array( $stdCodistoProductChangeStmt, $stdCodistoProductChangeStmt, $stdCodistoProductDeleteStmt ),
                                'catalog_product_entity_gallery' => array( $stdCodistoProductChangeStmt, $stdCodistoProductChangeStmt, $stdCodistoProductDeleteStmt ),
                                'catalog_product_entity_group_price' => array( $stdCodistoProductChangeStmt, $stdCodistoProductChangeStmt, $stdCodistoProductDeleteStmt ),
                                'catalog_product_entity_int' => array( $stdCodistoProductChangeStmt, $stdCodistoProductChangeStmt, $stdCodistoProductDeleteStmt ),
                                'catalog_product_entity_media_gallery' => array( $stdCodistoProductChangeStmt, $stdCodistoProductChangeStmt, $stdCodistoProductDeleteStmt ),
                                'catalog_product_entity_text' => array( $stdCodistoProductChangeStmt, $stdCodistoProductChangeStmt, $stdCodistoProductDeleteStmt ),
                                'catalog_product_entity_varchar' => array( $stdCodistoProductChangeStmt, $stdCodistoProductChangeStmt, $stdCodistoProductDeleteStmt ),
                                'cataloginventory_stock_item' => array(
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_product_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_product_change` SET product_id = NEW.product_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE product_id = product_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_product_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_product_change` SET product_id = NEW.product_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE product_id = product_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_product_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_product_change` SET product_id = OLD.product_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE product_id = product_id, stamp = UTC_TIMESTAMP(); END IF;'
                                ),
                                'cataloginventory_stock_status' => array(
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_product_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_product_change` SET product_id = NEW.product_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE product_id = product_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_product_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_product_change` SET product_id = NEW.product_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE product_id = product_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_product_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_product_change` SET product_id = OLD.product_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE product_id = product_id, stamp = UTC_TIMESTAMP(); END IF;'
                                ),
                                'catalog_category_entity' => array( $stdCodistoCategoryChangeStmt, $stdCodistoCategoryChangeStmt, $stdCodistoCategoryDeleteStmt ),
                                'catalog_category_entity_datetime' => array( $stdCodistoCategoryChangeStmt, $stdCodistoCategoryChangeStmt, $stdCodistoCategoryDeleteStmt ),
                                'catalog_category_entity_decimal' => array( $stdCodistoCategoryChangeStmt, $stdCodistoCategoryChangeStmt, $stdCodistoCategoryDeleteStmt ),
                                'catalog_category_entity_int' => array( $stdCodistoCategoryChangeStmt, $stdCodistoCategoryChangeStmt, $stdCodistoCategoryDeleteStmt ),
                                'catalog_category_entity_text' => array( $stdCodistoCategoryChangeStmt, $stdCodistoCategoryChangeStmt, $stdCodistoCategoryDeleteStmt ),
                                'catalog_category_entity_varchar' => array( $stdCodistoCategoryChangeStmt, $stdCodistoCategoryChangeStmt, $stdCodistoCategoryDeleteStmt ),
                                'catalog_category_product' => array(
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_product_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_product_change` SET product_id = NEW.product_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE product_id = product_id, stamp = UTC_TIMESTAMP(); END IF;'.
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_category_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_category_change` SET category_id = NEW.category_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE category_id = category_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_product_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_product_change` SET product_id = NEW.product_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE product_id = product_id, stamp = UTC_TIMESTAMP(); END IF;'.
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_category_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_category_change` SET category_id = NEW.category_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE category_id = category_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_product_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_product_change` SET product_id = OLD.product_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE product_id = product_id, stamp = UTC_TIMESTAMP(); END IF;'.
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_category_change\') THEN INSERT INTO `'.$tablePrefix.'codisto_category_change` SET category_id = OLD.category_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE category_id = category_id, stamp = UTC_TIMESTAMP(); END IF;'
                                ),
                                'sales_flat_order' => array(
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND COALESCE(NEW.codisto_orderid, \'\') != \'\' THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = NEW.entity_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND COALESCE(NEW.codisto_orderid, \'\') != \'\' THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = NEW.entity_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND COALESCE(OLD.codisto_orderid, \'\') != \'\' THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = OLD.entity_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;'
                                ),
                                'sales_flat_invoice' => array(
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND EXISTS(SELECT 1 FROM `'.$tablePrefix.'sales_flat_order` WHERE entity_id = NEW.order_id AND COALESCE(codisto_orderid, \'\') != \'\') THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = NEW.order_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND EXISTS(SELECT 1 FROM `'.$tablePrefix.'sales_flat_order` WHERE entity_id = NEW.order_id AND COALESCE(codisto_orderid, \'\') != \'\') THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = NEW.order_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND EXISTS(SELECT 1 FROM `'.$tablePrefix.'sales_flat_order` WHERE entity_id = OLD.order_id AND COALESCE(codisto_orderid, \'\') != \'\') THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = OLD.order_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;'
                                ),
                                'sales_flat_shipment' => array(
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND EXISTS(SELECT 1 FROM `'.$tablePrefix.'sales_flat_order` WHERE entity_id = NEW.order_id AND COALESCE(codisto_orderid, \'\') != \'\') THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = NEW.order_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND EXISTS(SELECT 1 FROM `'.$tablePrefix.'sales_flat_order` WHERE entity_id = NEW.order_id AND COALESCE(codisto_orderid, \'\') != \'\') THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = NEW.order_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND EXISTS(SELECT 1 FROM `'.$tablePrefix.'sales_flat_order` WHERE entity_id = OLD.order_id AND COALESCE(codisto_orderid, \'\') != \'\') THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = OLD.order_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;'
                                ),
                                'sales_flat_shipment_track' => array(
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND EXISTS(SELECT 1 FROM `'.$tablePrefix.'sales_flat_order` WHERE entity_id = NEW.order_id AND COALESCE(codisto_orderid, \'\') != \'\') THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = NEW.order_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND EXISTS(SELECT 1 FROM `'.$tablePrefix.'sales_flat_order` WHERE entity_id = NEW.order_id AND COALESCE(codisto_orderid, \'\') != \'\') THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = NEW.order_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;',
                                    'IF EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \''.$tablePrefix.'codisto_order_change\') AND EXISTS(SELECT 1 FROM `'.$tablePrefix.'sales_flat_order` WHERE entity_id = OLD.order_id AND COALESCE(codisto_orderid, \'\') != \'\') THEN INSERT INTO `'.$tablePrefix.'codisto_order_change` SET order_id = OLD.order_id, stamp = UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE order_id = order_id, stamp = UTC_TIMESTAMP(); END IF;'
                                )
                            );

            $triggerRules = array();
            foreach($triggerStaticRules as $table => $statements) {
                $triggerRules[$tablePrefix.$table] = array( 'table' => $table, 'statements' => $statements );
            }

            $adapter->query('CREATE TEMPORARY TABLE `codisto_triggers` ( `table` varchar(100) NOT NULL PRIMARY KEY, `insert_statement` varchar(2000) NOT NULL, `update_statement` varchar(2000) NOT NULL, `delete_statement` varchar(2000) NOT NULL )');
            foreach($triggerRules as $table => $tableData) {
                $adapter->insert('codisto_triggers', array( 'table' => $table, 'insert_statement' => $tableData['statements'][0], 'update_statement' => $tableData['statements'][1], 'delete_statement' => $tableData['statements'][2] ) );
            }

            $missingTriggers = $adapter->fetchAll(
                'SELECT T.`table`, '.
                        'TYPE.`type`, '.
                        'CASE WHEN TRIGGER_NAME IS NULL THEN 0 ELSE -1 END AS `exists`, '.
                        'COALESCE(EXISTING.TRIGGER_CATALOG, \'\') AS `current_catalog`, '.
                        'COALESCE(EXISTING.TRIGGER_SCHEMA, \'\') AS `current_schema`, '.
                        'COALESCE(EXISTING.TRIGGER_NAME, \'\') AS `current_name`, '.
                        'COALESCE(EXISTING.ACTION_STATEMENT, \'\') AS `current_statement`, '.
                        'COALESCE(EXISTING.DEFINER, \'\') AS `current_definer`, '.
                        'COALESCE(EXISTING.SQL_MODE, \'\') AS `current_sqlmode` '.
                        'FROM `codisto_triggers` AS T '.
                            'CROSS JOIN (SELECT \'UPDATE\' AS `type` UNION ALL SELECT \'INSERT\' UNION ALL SELECT \'DELETE\') AS TYPE '.
                            'LEFT JOIN INFORMATION_SCHEMA.TRIGGERS AS EXISTING ON EXISTING.EVENT_OBJECT_TABLE = T.`table` AND EXISTING.ACTION_TIMING = \'AFTER\' AND EXISTING.EVENT_MANIPULATION = TYPE.`type` '.
                        'WHERE NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TRIGGERS WHERE EVENT_OBJECT_TABLE = T.`table` AND ACTION_TIMING = \'AFTER\' AND EVENT_MANIPULATION = TYPE.`type` AND ACTION_STATEMENT LIKE CONCAT(\'%\', CASE WHEN TYPE.`type` = \'INSERT\' THEN T.`insert_statement` WHEN TYPE.`type` = \'UPDATE\' THEN T.`update_statement` WHEN TYPE.`type` = \'DELETE\' THEN T.`delete_statement` END, \'%\'))');

            $changeTriggersExist = true;

            if(count($missingTriggers) > 0) {
                $changeTriggersExist = false;

                $triggerTypeMap = array( 'INSERT' => 0, 'UPDATE' => 1, 'DELETE' => 2 );

                $existingTriggers = array();
                foreach($missingTriggers as $trigger) {
                    if(isset($trigger['current_name']) && $trigger['current_name'] &&
                        $trigger['current_statement']) {
                        $existingTriggers[] = array(
                            'current_definer' => $trigger['current_definer'],
                            'current_schema' => $trigger['current_schema'],
                            'current_name' => $trigger['current_name'],
                            'current_statement' => $trigger['current_statement'],
                            'current_sqlmode' => $trigger['current_sqlmode'],
                            'type' => $trigger['type'],
                            'table' => $trigger['table']
                        );
                    }
                }

                if(!empty($existingTriggers)) {
                    $adapter->query('CREATE TABLE IF NOT EXISTS `'.$tablePrefix.'codisto_trigger_backup` (definer text NOT NULL, current_schema text NOT NULL, current_name text NOT NULL, current_statement text NOT NULL, type text NOT NULL, `table` text NOT NULL, stamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');

                    foreach($existingTriggers as $trigger) {
                        $adapter->insert($tablePrefix.'codisto_trigger_backup', array(
                            'definer' => $trigger['current_definer'],
                            'current_schema' => $trigger['current_schema'],
                            'current_name' => $trigger['current_name'],
                            'current_statement' => $trigger['current_statement'],
                            'type' => $trigger['type'],
                            'table' => $trigger['table']
                        ));
                    }
                }

                foreach($missingTriggers as $trigger) {
                    $triggerRule = $triggerRules[$trigger['table']];

                    $table = $triggerRule['table'];
                    $statement = $triggerRule['statements'][$triggerTypeMap[$trigger['type']]];

                    try {
                        $final_statement = "\n/* start codisto change tracking trigger */\n".$statement."\n/* end codisto change tracking trigger */\n";

                        $adapter->query('DROP TRIGGER IF EXISTS codisto_'.$table.'_'.strtolower($trigger['type']));
                        $adapter->query('CREATE DEFINER = CURRENT_USER TRIGGER codisto_'.$table.'_'.strtolower($trigger['type']).' AFTER '.$trigger['type'].' ON `'.$trigger['table'].'`'."\n".'FOR EACH ROW BEGIN '.$final_statement.'END');

                        // TODO: loop on existing triggers for this class that match /* start codisto change tracking trigger */ and remove
                    } catch(Exception $e) {
                        if(method_exists($e, 'hasChainedException') &&
                            $e->hasChainedException() &&
                            $e->getChainedException() instanceof PDOException &&
                            is_array($e->getChainedException()->errorInfo) &&
                            $e->getChainedException()->errorInfo[1] == 1235) {
                            // this version of mysql doesn't support multiple triggers so let's modify the existing trigger

                            $current_statement = preg_replace('/^BEGIN|END$/i', '', $trigger['current_statement']);
                            $cleaned_statement = preg_replace('/\s*\/\*\s+start\s+codisto\s+change\s+tracking\s+trigger\s+\*\/.*\/\*\s+end\s+codisto\s+change\s+tracking\s+trigger\s+\*\/\n?\s*/is', '', $current_statement);
                            $final_statement = preg_replace('/;\s*;/', ';', $cleaned_statement."\n/* start codisto change tracking trigger */\n".$statement)
                                                ."\n/* end codisto change tracking trigger */\n";

                            if(!preg_match('/^\s/', $final_statement)) {
                                $final_statement = "\n".$final_statement;
                            }
                            if(!preg_match('/\s$/', $final_statement)) {
                                $final_statement = $final_statement."\n";
                            }

                            $definer = $trigger['current_definer'];
                            if(strpos($definer, '@') !== false) {
                                $definer = explode('@', $definer);
                                $definer[0] = '\''.$definer[0].'\'';
                                $definer[1] = '\''.$definer[1].'\'';
                                $definer = implode('@', $definer);
                            }

                            try {
                                $adapter->query('SET @saved_sql_mode = @@sql_mode');
                                $adapter->query('SET sql_mode = \''.$trigger['current_sqlmode'].'\'');
                                $adapter->query('DROP TRIGGER `'.$trigger['current_schema'].'`.`'.$trigger['current_name'].'`');
                                $adapter->query('CREATE DEFINER = '.$definer.' TRIGGER `'.$trigger['current_name'].'` AFTER '.$trigger['type'].' ON `'.$trigger['table'].'`'."\n".' FOR EACH ROW BEGIN'.$final_statement.'END');
                                $adapter->query('SET sql_mode = @saved_sql_mode');
                            } catch(Exception $e2) {
                                try {
                                    $adapter->query('SET @saved_sql_mode = @@sql_mode');
                                    $adapter->query('SET sql_mode = \''.$trigger['current_sqlmode'].'\'');
                                    $adapter->query('CREATE DEFINER = '.$definer.' TRIGGER `'.$trigger['current_name'].'` AFTER '.$trigger['type'].' ON `'.$trigger['table'].'`'."\n".'FOR EACH ROW '.$trigger['current_statement']);
                                    $adapter->query('SET sql_mode = @saved_sql_mode');
                                } catch(Exception $e3) {
                                    throw new Exception($e2->getMessage().' '.$e3->getMessage());
                                }

                                throw $e2;
                            }
                        } else {
                            throw $e;
                        }
                    }
                }
            }

            $adapter->query('DROP TABLE codisto_triggers');

            // check sync db exists
            $syncDbExists = false;
            $syncDb = null;

            try {
                $syncDb = new PDO('sqlite:' . $syncDbPath);

                $this->prepareSqliteDatabase( $syncDb, 60 );

                $qry = $syncDb->query('PRAGMA quick_check');

                $checkResult = $qry->fetchColumn();

                $qry->closeCursor();

                if($checkResult == 'ok') {
                    $syncDbExists = true;
                }
            } catch(Exception $e) {

            }

            // check sync db uuid and mage uuid
            $changeToken = null;
            try {
                $changeToken = $adapter->fetchOne('SELECT token FROM `'.$tablePrefix.'codisto_sync` WHERE store_id = '.(int)$storeId);
            } catch(Exception $e) {

            }

            $syncToken = null;
            if($syncDb) {
                $qry = null;
                try {
                    try {
                        $qry = $syncDb->query('SELECT token FROM sync');

                        $syncToken = $qry->fetchColumn();
                    } catch(Exception $e) {
                        if($qry) {
                            $qry->closeCursor();
                        }
                    }
                } catch(Exception $e) {

                }
            }

            return (!is_null($changeToken) && $changeToken != '') &&
                        ($changeToken == $syncToken) &&
                        $changeTablesExist &&
                        $changeTriggersExist &&
                        $syncDbExists;
        } catch(Exception $e) {
            return false;
        }
    }

    public function cleanSyncFolder()
    {
        $file = new Varien_Io_File();

        $syncFolder = Mage::getBaseDir('var') . '/codisto/';

        if($file->fileExists($syncFolder, false /* dirs as well */)) {
            foreach(@glob($syncFolder.'sync-*', GLOB_NOESCAPE|GLOB_NOSORT) as $filePath) {
                if(preg_match('/-first-\d+\.db$/', $filePath) === 1 || preg_match('/\.db$/', $filePath) === 0) {
                    if(@filemtime($filePath) < time() - 86400) {
                        @unlink($filePath);
                    }
                }
            }
        }
    }

    public function logExceptionCodisto(Exception $e, $endpoint)
    {
        $request = Mage::app()->getRequest();

        try {
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
        } catch(Exception $e2) {
            Mage::log("Couldn't notify " . $endpoint . " endpoint of install error. Exception details " . $e->getMessage() . " on line: " . $e->getLine());
        }
    }

    public function eBayReIndex()
    {
        try {
            $indexer = Mage::getModel('index/process');
            $indexer->load('codistoebayindex', 'indexer_code');
            $indexer->reindexAll();
            $indexer->changeStatus(Mage_Index_Model_Process::STATUS_REQUIRE_REINDEX);
        } catch (Exception $e) {
            Mage::log($e->getMessage());
        }
    }

    public function getSyncPath($path)
    {
        $file = new Varien_Io_File();

        $base_path = Mage::getBaseDir('var') . '/codisto/';

        try {

            $file->checkAndCreateFolder( $base_path, 0777 );

        } catch (Exception $e) {

            return preg_replace( '/\/+/', '/', Mage::getBaseDir('var') . '/' . $path );

        }

        return preg_replace( '/\/+/', '/', $base_path . $path );
    }

    public function getSyncPathTemp($path)
    {
        $base_path = $this->getSyncPath('');

        return tempnam( $base_path , $path . '-' );
    }

    public function prepareSqliteDatabase($db, $timeout = 60, $pagesize = 65536)
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_TIMEOUT, $timeout);
        $db->exec('PRAGMA synchronous=OFF');
        $db->exec('PRAGMA temp_store=MEMORY');
        $db->exec('PRAGMA page_size='.$pagesize);
        $db->exec('PRAGMA encoding=\'UTF-8\'');
        $db->exec('PRAGMA cache_size=15000');
        $db->exec('PRAGMA soft_heap_limit=67108864');
        $db->exec('PRAGMA journal_mode=MEMORY');
    }

    public function getCACert()
    {
        if(!$this->caCertRequested) {

            try {
                $client = new Zend_Http_Client('http://ui.codisto.com/codisto.crt');
                $caResponse = $client->request('GET');

                if(!$caResponse->isError()) {

                    $file = new Varien_Io_File();

                    $codistoPath = Mage::getBaseDir('var') . '/codisto/';

                    $file->checkAndCreateFolder($codistoPath, 0777);

                    file_put_contents($codistoPath . 'codisto.crt', $caResponse->getRawBody());

                }

            } catch(Exception $e) {

            }

            $this->caCertRequested = true;
        }
    }

    private function phpTest($interpreter, $args, $script)
    {
        $process = proc_open('"'.$interpreter.'" '.$args, array(
            array('pipe', 'r'),
            array('pipe', 'w')
        ), $pipes);

        stream_set_blocking( $pipes[0], 0 );
        stream_set_blocking( $pipes[1], 0 );

        stream_set_timeout( $pipes[0], 5 );
        stream_set_timeout( $pipes[1], 10 );

        $write_total = strlen( $script );
        $written = 0;

        while($write_total > 0)
        {
            $write_count = @fwrite($pipes[0], substr( $script, $written ) );
            if($write_count === false)
            {
                @fclose( $pipes[0] );
                @fclose( $pipes[1] );
                @proc_terminate( $process, 9 );
                @proc_close( $process );

                return '';
            }

            $write_total -= $write_count;
            $written += $write_count;
        }

        @fclose( $pipes[0] );

        $result = '';
        while(!feof($pipes[1]))
        {
            $result .= @fread($pipes[1], 8192);
            if($result === false)
            {
                @fclose( $pipes[1] );
                @proc_terminate( $process, 9 );
                @proc_close( $process );

                return '';
            }
        }

        if(!$result)
            $result = '';

        @fclose( $pipes[1] );
        @proc_close( $process );

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
                                        array_map(function($ext) {
                                            return "'".$ext."'".' => '. 'extension_loaded'."('".$ext."')";},
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
        if('\\' === DIRECTORY_SEPARATOR) {
            $extension = '.exe';
        }

        $dirs = array(PHP_BINDIR);
        if('\\' === DIRECTORY_SEPARATOR) {
            $dirs[] = getenv('SYSTEMDRIVE').'\\xampp\\php\\';
        }

        $open_basedir = ini_get('open_basedir');
        if($open_basedir) {
            $basedirs = explode(PATH_SEPARATOR, ini_get('open_basedir'));
            foreach($basedirs as $dir) {
                if(@is_dir($dir)) {
                    $dirs[] = $dir;
                }
            }
        } else {
            $dirs = array_merge(explode(PATH_SEPARATOR, getenv('PATH')), $dirs);
        }

        foreach($dirs as $dir) {
            foreach($interpreterName as $fileName) {
                $file = $dir.DIRECTORY_SEPARATOR.$fileName.$extension;

                if(@is_file($file) && ('\\' === DIRECTORY_SEPARATOR || @is_executable($file))) {
                    $file = $this->phpCheck($file, '5.0.0', $requiredExtensions);
                    if(!$file) {
                        continue;
                    }

                    $this->phpInterpreter = $file;

                    return $file;
                }
            }
        }

        if(function_exists('shell_exec')) {
            foreach($interpreterName as $fileName) {
                $file = shell_exec('which '.$fileName.$extension);
                if($file) {
                    $file = trim($file);
                    if(@is_file($file) && ('\\' === DIRECTORY_SEPARATOR || @is_executable($file))) {
                        $file = $this->phpCheck($file, '5.0.0', $requiredExtensions);
                        if(!$file) {
                            continue;
                        }

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
        if(function_exists('proc_open')) {
            $interpreter = $this->phpPath($extensions);
            if($interpreter) {
                $curl_cainfo = ini_get('curl.cainfo');
                if(!$curl_cainfo && getenv('CURL_CA_BUNDLE')) {
                    $curl_cainfo = getenv('CURL_CA_BUNDLE');
                }
                if(!$curl_cainfo && getenv('SSL_CERT_FILE')) {
                    $curl_cainfo = getenv('SSL_CERT_FILE');
                }

                $cmdline = '';
                foreach($args as $arg) {
                    $cmdline .= '\''.$arg.'\' ';
                }

                if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    $process = proc_open('start /b '.$interpreter.' "'.$script.'" '.$cmdline, array(), $pipes, Mage::getBaseDir('base'), array( 'CURL_CA_BUNDLE' => $curl_cainfo ));
                } else {
                    $process = proc_open($interpreter.' "'.$script.'" '.$cmdline.' &', array(), $pipes, Mage::getBaseDir('base'), array( 'CURL_CA_BUNDLE' => $curl_cainfo ));
                }

                if(is_resource($process)) {
                    @proc_close($process);
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
                if(!$curl_cainfo && getenv('CURL_CA_BUNDLE')) {
                    $curl_cainfo = getenv('CURL_CA_BUNDLE');
                }
                if(!$curl_cainfo && getenv('SSL_CERT_FILE')) {
                    $curl_cainfo = getenv('SSL_CERT_FILE');
                }

                $cmdline = '';
                if(is_array($cmdline)) {
                    foreach($args as $arg) {
                        $cmdline .= '\''.$arg.'\' ';
                    }
                }

                $descriptors = array(
                        1 => array('pipe', 'w')
                );

                if(is_string($stdin)) {
                    $descriptors[0] = array('pipe', 'r');
                }

                $process = proc_open($interpreter.' "'.$script.'" '.$cmdline,
                            $descriptors, $pipes, Mage::getBaseDir('base'), array( 'CURL_CA_BUNDLE' => $curl_cainfo ));
                if(is_resource($process)) {
                    stream_set_blocking( $pipes[0], 0 );
                    stream_set_blocking( $pipes[1], 0 );

                    stream_set_timeout( $pipes[0], 5 );
                    stream_set_timeout( $pipes[1], 30 );

                    if(is_string($stdin)) {
                        for($written = 0; $written < strlen($stdin); ) {
                            $writecount = fwrite($pipes[0], substr($stdin, $written));
                            if($writecount === false) {
                                @fclose( $pipes[0] );
                                @fclose( $pipes[1] );
                                @proc_terminate( $process, 9 );
                                @proc_close( $process );
                                return null;
                            }

                            $written += $writecount;
                        }

                        @fclose($pipes[0]);
                    }

                    $result = '';
                    while(!feof($pipes[1])) {
                        $result .= @fread($pipes[1], 8192);
                        if($result === false) {
                            @fclose( $pipes[1] );
                            @proc_terminate( $process, 9 );
                            @proc_close( $process );

                            return '';
                        }
                    }

                    @fclose($pipes[1]);
                    @proc_close($process);
                    return $result;
                }
            }
        }

        return null;
    }

    public function processCmsContent($content)
    {
        if(strpos($content, '{{') === false) {
            return trim($content);
        }

        $result = $this->runProcess(realpath(dirname(__FILE__)).'/CmsContent.php', null, array('pdo', 'curl', 'simplexml'), $content);
        if($result != null) {
            return $result;
        }

        return Mage::helper('cms')->getBlockTemplateProcessor()->filter(trim($content));
    }

    public function signalOnShutdown($merchants, $msg, $eventtype, $productids)
    {
        try {

            $backgroundSignal = $this->runProcessBackground(realpath(dirname(__FILE__)).'/Signal.php', array(serialize($merchants), $msg, $eventtype, serialize($productids)), array('pdo', 'curl', 'simplexml'));
            if($backgroundSignal) {
                return;
            }

            if(is_array($productids)) {
                $syncObject = Mage::getModel('codistosync/sync');

                $storeVisited = array();

                foreach($merchants as $merchant) {
                    $storeId = $merchant['storeid'];

                    if(!isset($storeVisited[$storeId])) {
                        if($storeId == 0) {
                            // jump the storeid to first non admin store
                            $stores = Mage::getModel('core/store')->getCollection()
                                                        ->addFieldToFilter('is_active', array('neq' => 0))
                                                        ->addFieldToFilter('store_id', array('gt' => 0))
                                                        ->setOrder('store_id', 'ASC');

                            if($stores->getSize() == 1) {
                                $stores->setPageSize(1)->setCurPage(1);
                                $firstStore = $stores->getFirstItem();
                                if(is_object($firstStore) && $firstStore->getId()) {
                                    $storeId = $firstStore->getId();
                                }
                            }
                        }

                        $syncDb = $this->getSyncPath('sync-'.$storeId.'.db');

                        if($eventtype == Mage_Index_Model_Event::TYPE_DELETE) {
                            $syncObject->DeleteProducts($syncDb, $productids, $storeId);
                        } else {
                            $syncObject->UpdateProducts($syncDb, $productids, $storeId);
                        }

                        $storeVisited[$storeId] = 1;
                    }
                }
            }

            if(!$this->client) {
                $curlOptions = array(CURLOPT_TIMEOUT => 4, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0);
                $curlCA = Mage::getBaseDir('var') . '/codisto/codisto.crt';
                if(is_file($curlCA)) {
                    $curlOptions[CURLOPT_CAINFO] = $curlCA;
                }

                $this->client = new Zend_Http_Client();
                $this->client->setConfig(array( 'adapter' => 'Zend_Http_Client_Adapter_Curl', 'curloptions' => $curlOptions, 'keepalive' => true, 'maxredirects' => 0 ));
                $this->client->setStream();
            }

            foreach($merchants as $merchant) {
                try {
                    $this->client->setUri('https://api.codisto.com/'.$merchant['merchantid']);
                    $this->client->setHeaders('X-HostKey', $merchant['hostkey']);
                    $this->client->setRawData($msg)->request('POST');
                } catch(Exception $e) {

                }
            }
        } catch(Exception $e) {
            Mage::log('error signalling '.$e->getMessage(), null, 'codisto.log');
        }
    }

    public function signal($merchants, $msg, $eventtype = null, $productids = null)
    {
        register_shutdown_function(array($this, 'signalOnShutdown'), $merchants, $msg, $eventtype, $productids);
    }
}
