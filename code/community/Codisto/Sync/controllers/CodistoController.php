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

class Codisto_Sync_CodistoController extends Mage_Adminhtml_Controller_Action
{
	public $_publicActions = array('index', 'intro', 'settings', 'orders');
	private $helper;

	function __construct() {

		parent::__construct();
		$this->helper = Mage::helper('codisto/sync');

 	}

	public function indexAction()
	{
		$re = '/\/ebaytab\/?/';
		$replace = '/ebaytab/';
		if(Mage::getStoreConfig('admin/security/use_form_key'))
		{
			$re = '/\/ebaytab\/index\/key\//';
			$replace = '/ebaytab/key/';
		}

		$url = preg_replace($re, $replace, Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

		$this->renderPane($url, 'codisto-bulk-editor');
	}

	public function ordersAction()
	{
		$re = '/\/ebaytab\/?/';
		$replace = '/orders/';
		if(Mage::getStoreConfig('admin/security/use_form_key'))
		{
			$re = '/\/ebaytab\/index\/key\//';
			$replace = '/orders/key/';
		}

		$url = preg_replace($re, $replace, Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

		$action = $this->getRequest()->getQuery('action');
		if($action)
			$url = $url . '?action='. $action;

		$this->renderPane($url, 'codisto-bulk-editor');
	}

	public function categoriesAction()
	{
		$re = '/\/ebaytab\/?/';
		$replace = '/ebaytab/categories/';
		if(Mage::getStoreConfig('admin/security/use_form_key'))
		{
			$re = '/\/ebaytab\/index\/key\//';
			$replace = '/ebaytab/categories/key/';
		}

		$url = preg_replace($re, $replace, Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

		$this->renderPane($url, 'codisto-bulk-editor');
	}

	public function importAction()
	{
		$re = '/\/ebaytab\/?/';
		$replace = '/ebaytab/importlistings/';
		if(Mage::getStoreConfig('admin/security/use_form_key'))
		{
			$re = '/\/ebaytab\/index\/key\//';
			$replace = '/ebaytab/importlistings/key/';
		}

		$url = preg_replace($re, $replace, Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'). '?v=2');

		$this->renderPane($url, 'codisto-bulk-editor');
	}

	public function introAction()
	{
		$re = '/\/ebaytab\/?/';
		$replace = '/ebaytab/';
		if(Mage::getStoreConfig('admin/security/use_form_key'))
		{
			$re = '/\/ebaytab\/index\/key\//';
			$replace = '/ebaytab/key/';
		}

		$url = preg_replace($re, $replace, Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab')) . '?intro=1';

		$this->renderPane($url, 'codisto-bulk-editor');
	}

	public function attributemappingAction()
	{
		$re = '/\/ebaytab\/?/';
		$replace = '/ebaytab/attributemapping/';
		if(Mage::getStoreConfig('admin/security/use_form_key'))
		{
			$re = '/\/ebaytab\/index\/key\//';
			$replace = '/ebaytab/attributemapping/key/';
		}

		$url = preg_replace($re, $replace, Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

		$this->renderPane($url, 'codisto-attributemapping');
	}

	public function settingsAction()
	{
		$re = '/\/ebaytab\/?/';
		$replace = '/settings/';
		if(Mage::getStoreConfig('admin/security/use_form_key'))
		{
			$re = '/\/ebaytab\/index\/key\//';
			$replace = '/settings/key/';
		}

		$url = preg_replace($re, $replace, Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

		$this->renderPane($url, 'codisto-settings');
	}

	private function renderPane($url, $class)
	{
		$this->loadLayout();

		$block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<div id="codisto-control-panel-wrapper"><iframe id="codisto-control-panel" class="codisto-iframe '. htmlspecialchars($class) .'" src="'. htmlspecialchars($url) . '" frameborder="0" onmousewheel=""></iframe></div>');
		$this->_addContent($block);

		$this->renderLayout();
	}
}
