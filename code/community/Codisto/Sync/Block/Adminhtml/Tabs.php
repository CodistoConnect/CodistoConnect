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
class Codisto_Sync_Block_Adminhtml_Tabs extends Mage_Adminhtml_Block_Catalog_Product_Edit_Tabs
{
	private $parent;

	protected function _prepareLayout()
	{
		//get all existing tabs
		$this->parent = parent::_prepareLayout();
		
		$product = $this->getProduct();

		$entity_id = $product->getEntityId();
		$type = $product->getTypeId();

		if(isset($entity_id) && $type != 'grouped')
		{		
			$url = Mage::getModel('adminhtml/url')->getUrl('adminhtml/codisto/ebaytab/', array('product' => $entity_id, 'iframe' => true));

			//add new tab
			$this->addTab('tabid', array(
				'label'     => 'eBay',
				'class'	    => 'ajax',
				'url'	    => $url
			));
		}
		
		return $this->parent;
	}
}
