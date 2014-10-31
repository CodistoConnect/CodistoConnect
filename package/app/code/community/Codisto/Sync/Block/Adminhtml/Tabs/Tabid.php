<?php

class Codisto_Sync_Block_Adminhtml_Tabs_Tabid extends Mage_Adminhtml_Block_Widget
{
	public function __construct()
	{
		parent::__construct();
		$this->setTemplate('Sync/newtab.phtml');
	}
}