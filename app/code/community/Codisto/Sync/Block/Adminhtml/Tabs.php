<?php

class Codisto_Sync_Block_Adminhtml_Tabs extends Mage_Adminhtml_Block_Catalog_Product_Edit_Tabs
{
	private $parent;

	protected function _prepareLayout()
	{

		//get all existing tabs
		$this->parent = parent::_prepareLayout();

		$MerchantID = Mage::getStoreConfig('codisto/merchantid');
		$ApiKey = Mage::getStoreConfig('codisto/apikey');
		$HostKey = Mage::getStoreConfig('codisto/hostkey');
		$HostID = Mage::getStoreConfig('codisto/hostid');
		$PartnerID = Mage::getStoreConfig('codisto/partnerid');
		$PartnerKey = Mage::getStoreConfig('codisto/partnerkey');

		$product = $this->getProduct()->getData();
		if(isset($product['entity_id']))
		{
		
//syslog(1, "MerchantID:" . $MerchantID);
//syslog(1, "MerchantID:" . $HostKey);
//syslog(1, "baseurl:" . Mage::getUrl('',array('_forced_secure'=>true)));
//syslog(1, "baseurl:" . Mage::getBaseUrl());
//syslog(1, "baseurl:" . Mage::getStoreConfig(Mage_Core_Model_Store::XML_PATH_SECURE_BASE_URL));			
		
			$url = Mage::getUrl('',array('_forced_secure'=>true)) . "codisto-sync/sync/proxyGet/?proxy_url=" . urlencode("https://secure.ezimerchant.com/" . $MerchantID . "/frame/1/product/" . $product['entity_id'] . "/ebay/");

			//add new tab
			$this->addTab('tabid', array(
				'label'     => 'Codisto eBay Plugin',
				'content'   => "<iframe id='codisto' width=\"100%\" height=\"800\" src=\"${url}\"></iframe>"
			));
		}
		return $this->parent;
	}
}