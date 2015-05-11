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

class Codisto_Sync_CodistoController extends Mage_Adminhtml_Controller_Action
{
	public function indexAction()
	{
		$url = Mage::getModel('adminhtml/url')->getUrl('adminhtml/codisto/ebaytab/index');

		$this->loadLayout();

		$block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<div id="codisto-control-panel-wrapper"><iframe id="codisto-control-panel" src="'. $url . '" frameborder="0"></iframe></div>');
		$this->_addContent($block);

		$this->renderLayout();
	}

	public function gettingstartedAction()
	{
		$url = Mage::getModel('adminhtml/url')->getUrl('adminhtml/codisto/gettingstarted');

		$this->loadLayout();

		$block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<div id="codisto-control-panel-wrapper"><iframe id="codisto-control-panel" src="'. $url . '" frameborder="0"></iframe></div>');
		$this->_addContent($block);

		$this->renderLayout();
	}
}
