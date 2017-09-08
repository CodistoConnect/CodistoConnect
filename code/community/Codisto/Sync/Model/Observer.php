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

class Codisto_Sync_Model_Observer
{

    public function cronSync($synctype)
    {
        $SyncTimeout = 600;
        $Sleep = 100000;
        $ConfigurableCount = 6;
        $SimpleCount = 250;

        $helper = Mage::helper('codistosync');

        $file = new Varien_Io_File();

        $indexer = Mage::getModel('index/process')->load('codistoebayindex', 'indexer_code');

        try {
            if($indexer->load('codistoebayindex', 'indexer_code')->getStatus() == 'working') {
                return;
            }

            $extSyncFailed = $helper->getSyncPath('codisto-external-sync-failed');
            $extTestFailed = $helper->getSyncPath('codisto-external-test-failed');

            if(!file_exists($extSyncFailed) && !file_exists($extTestFailed)) {
                return 'External sync has not failed, manual sync not run';
            }

            $merchants = array();
            $visited = array();

            $stores = Mage::getModel('core/store')->getCollection();
            foreach($stores as $store) {
                $merchantlist = Zend_Json::decode($store->getConfig('codisto/merchantid'));

                if($merchantlist) {
                    if(!is_array($merchantlist)) {
                        $merchantlist = array($merchantlist);
                    }

                    foreach($merchantlist as $merchantId) {
                        if(!in_array($merchantId, $visited, true)) {
                            $merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $store->getConfig('codisto/hostkey'), 'storeid' => $store->getId() );
                            $visited[] = $merchantId;
                        }
                    }
                }
            }

            $merchantlist = Zend_Json::decode(Mage::getStoreConfig('codisto/merchantid', 0));
            if($merchantlist) {
                $HostKey = Mage::getStoreConfig('codisto/hostkey', 0);

                if(!is_array($merchantlist)) {
                    $merchantlist = array($merchantlist);
                }

                foreach($merchantlist as $merchantId) {
                    if(!in_array($merchantId, $visited, true)) {
                        $merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $HostKey, 'storeid' => 0);
                        $visited[] = $merchantId;
                    }
                }
            }

            unset($visited);

            $client = new Zend_Http_Client();
            $client->setConfig(array( 'keepalive' => false, 'maxredirects' => 0, 'timeout' => 30 ));
            $client->setStream();

            foreach($merchants as $merchant) {
                try {
                    $client->setUri('https://ui.codisto.com/'.$merchant['merchantid'].'/testendpoint/');
                    $client->setHeaders('X-HostKey', $merchant['hostkey']);
                    $remoteResponse = $client->request('GET');

                    $data = Zend_Json::decode($remoteResponse->getRawBody(), true);

                    if(isset($testdata['ack']) && $testdata['ack'] == "SUCCESS") {
                        if(file_exists($extSyncFailed)) {
                            unlink($extSyncFailed);
                            unlink($extTestFailed);
                        }
                        break;
                    }
                } catch(Exception $e) {

                    Mage::log('Error posting to https://ui.codisto.com/testendpoint'.$merchant['merchantid'].'/: ' . $e->getMessage() . ' on line: ' . $e->getLine() . ' in file: ' . $e->getFile() . ' for merchant ' . $merchant['merchantid']);

                }
            }

            if(!file_exists($extSyncFailed)) {
                return; //External endpoint now reachable! Manual sync not run
            }

            if(file_exists($extTestFailed)) {
                return; //Test endpoint again
            }


            $file->open( array('path' => $helper->getSyncPath('') ) );
            $lastSyncTime = $file->read('codisto-external-sync-failed');

            if((microtime(true) - $lastSyncTime) < 1800) {
                return; //The manual cron sync has already run in the last 30 mins
            }

            if($indexer->load('codistoebayindex', 'indexer_code')->getStatus() == 'working') {
                return;
            }

            for($Retry = 0; ; $Retry++) {
                try {
                    $indexer->changeStatus(Mage_Index_Model_Process::STATUS_RUNNING);
                    break;
                } catch(Exception $e) {
                    if($Retry >= 3)
                        throw $e;

                    usleep(500000);
                    continue;
                }
            }

            $http = new Zend_Http_Client();
            $http->setConfig(array( 'keepalive' => false, 'maxredirects' => 0, 'timeout' => 5 ));
            $http->setStream();

            foreach($merchants as $merchant) {

                $storeId = $merchant['storeid'];
                $syncObject = Mage::getModel('codistosync/sync');
                $syncDb = $helper->getSyncPath('sync-cron-'.$storeId.'.db');

                $startTime = microtime(true);

                for($i = 0;;$i++) {

                    try {

                        $http->setUri('https://ui.codisto.com/'.$merchant['merchantid'].'/');
                        $http->setHeaders(array('Content-Type' => 'multipart/form-data'));
                        $http->setHeaders('X-HostKey', $merchant['hostkey']);
                        $http->setParameterPost(array('cmd'  => 'updatestatus', 'status' => 'inprogress', 'progress' => (20+$i)));
                        $response = $http->request('POST');

                    } catch (Exception $e) {
                        // if we fail to update status - that's ok
                    }

                    $result = $syncObject->SyncChunk($syncDb, $SimpleCount, $ConfigurableCount, $storeId, false);

                    if($result == 'complete') {
                        $syncObject->SyncTax($syncDb, $storeId);
                        $syncObject->SyncStores($syncDb, $storeId);

                        for($Retry = 0; ; $Retry++) {
                            try {
                                $indexer->changeStatus(Mage_Index_Model_Process::STATUS_PENDING);
                                break;
                            } catch(Exception $e) {
                                if($Retry >= 3)
                                    break;

                                usleep(500000);
                            }
                        }
                        break;
                    }

                    $now = microtime(true);

                    if(($now - $startTime) > $SyncTimeout) {
                        break;
                    }

                    usleep($Sleep);
                }

                if($result == 'complete') {

                    try {
                        $http->setParameterPost(array('cmd'  => 'updatestatus', 'status' => 'complete'));
                        $response = $http->request('POST');
                    } catch(Exception $e) {

                    }
                }

                try {

                    if($result == 'complete' && file_exists($syncDb)) {

                        $http->setUri('https://ui.codisto.com/'.$merchant['merchantid'].'/');
                        $http->setHeaders(array('Content-Type' => 'multipart/form-data'));
                        $http->setParameterPost(array('cmd'  => 'pushalldata'));
                        $http->setFileUpload($syncDb, 'syncdb');
                        $http->setHeaders('X-HostKey', $merchant['hostkey']);
                        $response = $http->request('POST');

                        unlink($syncDb);

                    }

                } catch (Exception $e) {

                    try
                    {

                        $http->setUri('https://ui.codisto.com/'.$merchant['merchantid'].'/');
                        $http->setHeaders(array('Content-Type' => 'multipart/form-data'));
                        $http->setHeaders('X-HostKey', $merchant['hostkey']);
                        $http->setParameterPost(array('cmd'  => 'updatestatus', 'status' => 'failed'));
                        $response = $http->request('POST');

                    } catch (Exception $e) {

                    }

                    Mage::log('Error Posting sync data to ui.codisto.com: ' . $e->getMessage() . ' on line: ' . $e->getLine()  . ' in file: ' . $e->getFile() . ' for merchant ' . $merchant['merchantid']);

                }

            }


            $file->write('codisto-external-sync-failed', (string)microtime(true));

        } catch (Exception $e) {

            $file->write('codisto-external-sync-failed', 0);

            Mage::log('Codisto Sync Error: ' . $e->getMessage() . ' on line: ' . $e->getLine() . ' in file: ' . $e->getFile());

        }

        for($Retry = 0; ; $Retry++) {
            try {
                $indexer->changeStatus(Mage_Index_Model_Process::STATUS_PENDING);
                break;
            } catch(Exception $e) {
                if($Retry >= 3)
                    break;

                usleep(500000);
                continue;
            }
        }
    }

    public function catalogRuleAfterApply($observer)
    {
        if(Mage::registry('codisto_catalog_rule_after_apply')) {
            return;
        }

        $product = $observer->getProduct();
        if($product || is_numeric($product)) {
            Mage::register('codisto_catalog_rule_after_apply', 1);
            return;
        }

        $indexer = Mage::getModel('index/process');
        $indexer->load('codistoebayindex', 'indexer_code');
        $indexer->reindexAll();

        try {
            $indexer->changeStatus(Mage_Index_Model_Process::STATUS_REQUIRE_REINDEX);
        } catch(Exception $e) {

        }
    }

    public function paymentInfoBlockPrepareSpecificInformation($observer)
    {
        $transport = $observer->getEvent()->getTransport();
        $payment = $observer->getEvent()->getPayment();
        $paymentmethodinstance = is_object($payment) && $payment && method_exists($payment, 'getMethodInstance') ? $payment->getMethodInstance() : null;
        $paymentmethod = is_object($paymentmethodinstance) && $paymentmethodinstance && method_exists($paymentmethodinstance, 'getCode') ? $paymentmethodinstance->getCode() : '';

        if($paymentmethod == 'ebay' && Mage::getDesign()->getArea() == 'adminhtml') {
            $helper = Mage::helper('codistosync');

            $paypaltransactionid = $payment->getLastTransId();
            $order = $payment->getOrder();
            $orderid = $order->getCodistoOrderid();
            $storeid = $order->getStoreId();
            $merchantid = $order->getCodistoMerchantid();
            if(!$merchantid) {
                $merchantid = $helper->getMerchantId($storeid);
            }

            if($paypaltransactionid) {
                $transport['PayPal TransactionID_HTML'] = '<a href="'.htmlspecialchars(Mage::getUrl('', array('_secure' => true))).'codisto/ebaypayment/' . $merchantid . '?orderid='.htmlspecialchars($orderid).'" target="codisto!ebaypayment" class="codisto-ebay-payment-link">'.htmlspecialchars($paypaltransactionid).'</a>';
                $transport['PayPal TransactionID'] = $paypaltransactionid;
            }

            $additionalInfo = $payment->getData('additional_information');

            if(is_array($additionalInfo)) {
                if(isset($additionalInfo['ebaysalesrecordnumber']) &&
                    $additionalInfo['ebaysalesrecordnumber']) {
                    $transport['eBay Sales Record Number_HTML'] = '<a href="'.htmlspecialchars(Mage::getUrl('', array('_secure' => true))).'codisto/ebaysale/' . $merchantid . '?orderid='.htmlspecialchars($orderid).'" target="codisto!ebaysale" class="codisto-ebay-sales-link">'.htmlspecialchars($additionalInfo['ebaysalesrecordnumber']).'</a>';
                    $transport['eBay Sales Record Number'] = $additionalInfo['ebaysalesrecordnumber'];
                }

                if(isset($additionalInfo['ebayuser']) &&
                    $additionalInfo['ebayuser']) {
                    $transport['eBay User_HTML'] = '<a href="'.htmlspecialchars(Mage::getUrl('', array('_secure' => true))).'codisto/ebayuser/' . $merchantid . '?orderid='.htmlspecialchars($orderid).'" target="codisto!ebayuser" class="codisto-ebay-user-link">'.htmlspecialchars($additionalInfo['ebayuser']).'</a>';
                    $transport['eBay User'] = $additionalInfo['ebayuser'];
                }
            }
        }

        return $this;
    }

    public function taxSettingsChangeAfter(Varien_Event_Observer $observer)
    {
        $merchants = array();
        $visited = array();

        $helper = Mage::helper('codistosync');

        $stores = Mage::getModel('core/store')->getCollection();

        foreach($stores as $store) {
            $merchantlist = Zend_Json::decode($store->getConfig('codisto/merchantid'));
            if($merchantlist) {
                if(!is_array($merchantlist)) {
                    $merchantlist = array($merchantlist);
                }

                foreach($merchantlist as $merchantId) {
                    if(!in_array($merchantId, $visited, true)) {
                        $merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $store->getConfig('codisto/hostkey'), 'storeid' => $store->getId() );
                        $visited[] = $merchantId;
                    }
                }
            }
        }

        $merchantlist = Zend_Json::decode(Mage::getStoreConfig('codisto/merchantid', 0));
        if($merchantlist) {
            $HostKey = Mage::getStoreConfig('codisto/hostkey', 0);

            if(!is_array($merchantlist)) {
                $merchantlist = array($merchantlist);
            }

            foreach($merchantlist as $merchantId) {
                if(!in_array($merchantId, $visited, true)) {
                    $merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $HostKey, 'storeid' => 0);
                    $visited[] = $merchantId;
                }
            }
        }

        unset($visited);

        $helper->signal($merchants, 'action=synctax');

        return $this;
    }

    public function salesOrderShipmentSaveAfter(Varien_Event_Observer $observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();
        $orderid = $order->getCodistoOrderid();
        $storeId = $order->getStoreId();

        if($orderid) {
            $this->signalOrderChange( array( $orderid ), $storeId );
        }

        return $this;
    }

    public function salesOrderInvoiceSaveAfter(Varien_Event_Observer $observer)
    {
        $invoice = $observer->getEvent()->getInvoice();
        $order = $invoice->getOrder();
        $orderid = $order->getCodistoOrderid();
        $storeId = $order->getStoreId();

        if($orderid) {
            $this->signalOrderChange( array( $orderid ), $storeId );
        }

        return $this;
    }

    public function salesOrderShipmentTrackSaveAfter(Varien_Event_Observer $observer)
    {
        $track = $observer->getEvent()->getTrack();
        $shipment = $track->getShipment();
        $order = $shipment->getOrder();
        $orderid = $order->getCodistoOrderid();
        $storeId = $order->getStoreId();

        if($orderid) {
            $this->signalOrderChange( array( $orderid ), $storeId );
        }
    }

    public function checkoutAllSubmitAfter($observer)
    {
        if ($observer->getEvent()->hasOrders()) {
            $orders = $observer->getEvent()->getOrders();
        } else {
            $orders = array($observer->getEvent()->getOrder());
        }

        $stockItems = array();
        foreach ($orders as $order) {
            foreach ($order->getAllItems() as $orderItem) {
                if ($orderItem->getQtyOrdered()) {

                    $stockItems[] = $orderItem->getProductId();

                }
            }
        }

        if (!empty($stockItems)) {

            $this->signalStockChange($stockItems);

        }

        return $this;
    }

    public function catalogProductImportFinishBefore($observer)
    {
        $stockItems = array();
        $adapter = $observer->getEvent()->getAdapter();

        if($adapter &&
                method_exists($adapter, 'getAffectedEntityIds')) {
            $stockItems = $adapter->getAffectedEntityIds();
        }

        $this->signalStockChange($stockItems);

        return $this;
    }

    public function addProductTab($observer)
    {
        $block = $observer->getEvent()->getBlock();

        if($block instanceof Mage_Adminhtml_Block_Catalog_Product_Edit_Tabs) {

            $product = Mage::registry('product');

            $type = $product->getTypeId();

            if(in_array($type, array('simple', 'configurable', 'grouped'))) {
                $storeId = $block->getRequest()->getParam('store');
                if(!$storeId) {
                     $storeId = 0;
                }

                $merchantId = '';
                $merchantlist = Mage::getStoreConfig('codisto/merchantid', $storeId);
                if($merchantlist) {
                    $merchantlist = Zend_Json::decode($merchantlist);
                    if(is_array($merchantlist)) {
                        $merchantId = $merchantlist[0];
                    } else {
                        $merchantId = $merchantlist;
                    }
                }

                $entity_id = $product->getId();

                $url = preg_replace('/\/index\/product\//', '/product/', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab/index', array('product' => $entity_id, 'iframe' => 1)));
                if($merchantId) {
                    $url .= '?merchantid='.$merchantId.'&storeid='.$storeId;
                }

                $block->addTab('codisto_ebay_tab', array(
                    'label' => 'eBay',
                    'class' => 'ajax',
                    'url'   => $url
                ));
            }
        }
        return $this;
    }

    public function addScript($observer)
    {
        $helper = Mage::helper('codistosync');

        $version = $helper->getCodistoVersion();

        $merchantlist = Mage::getStoreConfig('codisto/merchantid', 0);
        if($merchantlist) {
            $merchantlist = Zend_Json::decode($merchantlist);
            if(is_array($merchantlist)) {
                $merchant = $merchantlist[0];
            } else {
                $merchant = $merchantlist;
            }
        } else {
            $merchant = '0';
        }

        $controller = $observer->getAction();
        $layout = $controller->getLayout();
        $block = $layout->createBlock('core/text');
        $block->setText(
        '<script type="text/javascript">
        window.codisto = {
            merchantid : '.$merchant.'
        };
        (function() {
            var s = document.createElement("script");
            s.src = "https://d31wxntiwn0x96.cloudfront.net/connect/" + window.codisto.merchantid + "/js/app/adminhtml.js?v'.$version.'";
            document.getElementsByTagName("HEAD")[0].appendChild(s);
        })();
        </script>');

        $jsBlock = $layout->getBlock('js');
        if($jsBlock) {
            $jsBlock->append($block);
        }

        return $this;
    }

    public function cmsStaticBlockSaveAfter($observer)
    {
        if(is_object($observer)) {
            $eventData = $observer->getEvent()->getData();

            if(isset($eventData['data_object'])) {
                $dataObject = $eventData['data_object'];

                if(is_subclass_of($dataObject, 'Mage_Core_Model_Abstract')) {
                    if($dataObject->getResourceName() == 'cms/block') {
                        $helper = Mage::helper('codistosync');

                        $merchants = array();
                        $visited = array();

                        $stores = Mage::getModel('core/store')->getCollection();

                        foreach($stores as $store) {
                            $merchantlist = Zend_Json::decode($store->getConfig('codisto/merchantid'));
                            if($merchantlist) {
                                if(!is_array($merchantlist)) {
                                    $merchantlist = array($merchantlist);
                                }

                                foreach($merchantlist as $merchantId) {
                                    if(!in_array($merchantId, $visited, true)) {
                                        $merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $store->getConfig('codisto/hostkey'), 'storeid' => $store->getId() );
                                        $visited[] = $merchantId;
                                    }
                                }
                            }
                        }

                        unset($visited);

                        $helper->signal($merchants, 'action=syncstaticblock&id='.rawurlencode($dataObject->getId()).'&identifier='.rawurlencode($dataObject->getIdentifier()).'&content='.rawurlencode($dataObject->getContent()));
                    }
                }
            }
        }

        return $this;
    }

    private function getMerchants() {

        $merchants = array();
        $visited = array();

        $stores = Mage::getModel('core/store')->getCollection();

        foreach($stores as $store) {
            $merchantlist = Zend_Json::decode($store->getConfig('codisto/merchantid'));
            if($merchantlist) {
                if(!is_array($merchantlist)) {
                    $merchantlist = array($merchantlist);
                }

                foreach($merchantlist as $merchantId) {
                    if(!in_array($merchantId, $visited, true)) {
                        $merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $store->getConfig('codisto/hostkey'), 'storeid' => $store->getId() );
                        $visited[] = $merchantId;
                    }
                }
            }
        }

        $MerchantID = Zend_Json::decode(Mage::getStoreConfig('codisto/merchantid', 0));
        $HostKey = Mage::getStoreConfig('codisto/hostkey', 0);
        if(!in_array($MerchantID, $visited, true)) {
            $merchants[] = array( 'merchantid' => $MerchantID, 'hostkey' => $HostKey, 'storeid' => 0);
        }

        unset($visited);

        return $merchants;

    }

    private function signalOrderChange($orderIds, $storeId)
    {
        $helper = Mage::helper('codistosync');

        $hostkey = Mage::getStoreConfig('codisto/hostkey', $storeId);

        $merchantList = Zend_Json::decode(Mage::getStoreConfig('codisto/merchantid', $storeId));
        if($merchantList) {
            if(!is_array($merchantList)) {
                $merchantList = array($merchantList);
            }

            $visited = array();

            foreach($merchantList as $merchantId) {
                if(!in_array($merchantId, $visited, true)) {
                    $merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $hostkey, 'storeid' => $storeId );
                    $visited[] = $merchantId;
                }
            }

            $helper->signal($merchants, 'action=syncorder&orderid='.Zend_Json::encode($orderIds));
        }
    }

    private function signalStockChange($stockItems)
    {
        if(!empty($stockItems)) {
            $helper = Mage::helper('codistosync');

            $merchants = array();
            $visited = array();

            $stores = Mage::getModel('core/store')->getCollection();

            foreach($stores as $store) {
                $merchantlist = Zend_Json::decode($store->getConfig('codisto/merchantid'));
                if($merchantlist) {
                    if(!is_array($merchantlist)) {
                        $merchantlist = array($merchantlist);
                    }

                    foreach($merchantlist as $merchantId) {
                        if(!in_array($merchantId, $visited, true)) {
                            $merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $store->getConfig('codisto/hostkey'), 'storeid' => $store->getId() );
                            $visited[] = $merchantId;
                        }
                    }
                }
            }

            $MerchantID = Zend_Json::decode(Mage::getStoreConfig('codisto/merchantid', 0));
            if(is_array($MerchantID)) {
                $MerchantID = $MerchantID[0];
            }
            $HostKey = Mage::getStoreConfig('codisto/hostkey', 0);
            if(!in_array($MerchantID, $visited, true)) {
                $merchants[] = array( 'merchantid' => $MerchantID, 'hostkey' => $HostKey, 'storeid' => 0);
            }

            unset($visited);

            $syncedProducts = Mage::registry('codisto_synced_products');
            if(!is_array($syncedProducts)) {
                $syncedProducts = array();
            }

            $syncIds = array_diff($stockItems, $syncedProducts);

            if(!empty($syncIds)) {
                foreach($syncIds as $productid) {
                    if(!in_array($productid, $syncedProducts)) {
                        $syncedProducts[] = $productid;
                    }
                }

                Mage::unregister('codisto_synced_products');
                Mage::register('codisto_synced_products', $syncedProducts);

                if(count($syncIds) == 1) {
                    $productids = $syncIds[0];
                } else {
                    $productids = '['.implode(',', $syncIds).']';
                }

                $helper->signal($merchants, 'action=sync&productid='.$productids, Mage_Index_Model_Event::TYPE_SAVE, $stockItems);
            }
        }
    }



    /* deprecated */
    public function stockRevertProductsSale($observer)
    {

    }

    public function cancelOrderItem($observer)
    {

    }
}
