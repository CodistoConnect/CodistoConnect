<?php
/**
 * Magento
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
 * @copyright   Copyright (c) 2012 On Technology (http://www.ontech.com.au)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
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
//array('_forced_secure'=>true)

			$url = Mage::getModel('adminhtml/url')->getUrl('adminhtml/codistoadmin/proxyGet') . "?proxy_url=" . urlencode("https://secure.ezimerchant.com/" . $MerchantID . "/frame/1/product/" . $product['entity_id'] . "/ebay/?formkey=".Mage::getSingleton('core/session')->getFormKey());

			//add new tab
			$this->addTab('tabid', array(
				'label'     => 'Codisto eBay Plugin',
				'content'   => "<iframe id='codisto' width=\"100%\" height=\"800\" src=\"${url}\"></iframe>"
			));
		}
		return $this->parent;
	}
}