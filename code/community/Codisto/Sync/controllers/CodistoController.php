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
	
	public $_publicActions = array('index', 'intro', 'settings');
	
	public function indexAction()
	{
		
		$url = preg_replace('/\/index\/key\//', '/key/', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

		$action = $this->getRequest()->getQuery('action');
		if($action)
			$url = $url . '?action='. $action;
	
		$this->loadLayout();

		$block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<div id="codisto-control-panel-wrapper"><iframe id="codisto-control-panel" class="codisto-iframe codisto-bulk-editor" src="'. $url . '" frameborder="0" onmousewheel=""></iframe></div>');
		$this->_addContent($block);

		$this->renderLayout();
	}
	
	public function categoriesAction()
	{
		
		$url = preg_replace('/\/index\/key\//', '/categories/key/', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

		$this->loadLayout();

		$block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<div id="codisto-control-panel-wrapper"><iframe id="codisto-control-panel" class="codisto-iframe codisto-bulk-editor" src="'. $url . '" frameborder="0" onmousewheel=""></iframe></div>');
		$this->_addContent($block);

		$this->renderLayout();
	}

	public function introAction()
	{
		
		$url = preg_replace('/\/index\/key\//', '/key/', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab')) . '?intro=1';

		$this->loadLayout();

		$block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<div id="codisto-control-panel-wrapper"><iframe id="codisto-control-panel" class="codisto-iframe codisto-bulk-editor" src="'. $url . '" frameborder="0" onmousewheel=""></iframe></div>');
		$this->_addContent($block);

		$this->renderLayout();
	}

	public function importAction()
	{
		
		$url = preg_replace('/\/index\/key\//', '/importlistings/key/', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'). '?v=2');

		$this->loadLayout();

		$block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<div id="codisto-control-panel-wrapper"><iframe id="codisto-control-panel" class="codisto-iframe codisto-bulk-editor" src="'. $url . '" frameborder="0" onmousewheel=""></iframe></div>');
		$this->_addContent($block);

		$this->renderLayout();
	}

	public function settingsAction()
	{
		$url = preg_replace('/\/ebaytab\/index\/key\//', '/settings/key/', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

		$this->loadLayout();

		$block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<div id="codisto-control-panel-wrapper"><iframe id="codisto-control-panel" class="codisto-iframe codisto-settings" src="'. $url . '" frameborder="0" onmousewheel=""></iframe></div>');
		$this->_addContent($block);

		$this->renderLayout();
	}
}
