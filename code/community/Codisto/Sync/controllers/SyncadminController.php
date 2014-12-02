<?php
class Codisto_Sync_SyncadminController extends Mage_Adminhtml_Controller_Action
{
	public function indexAction()
	{
		$url = Mage::getModel('adminhtml/url')->getUrl('adminhtml/codisto/ebaytab/index');
	
		$this->loadLayout();

		$block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<iframe id="codisto-control-panel" src="'. $url . '"frameborder="0" height="1600" width="100%"></iframe>');
		$this->_addContent($block);
		 
		$this->renderLayout();
	}
	
	public function gettingstartedAction()
	{
		$url = Mage::getModel('adminhtml/url')->getUrl('adminhtml/codisto/gettingstarted');
	
		$this->loadLayout();

		$block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<iframe id="codisto-control-panel" src="'. $url . '"frameborder="0" height="1600" width="100%"></iframe>');
		$this->_addContent($block);
		 
		$this->renderLayout();
	}
}