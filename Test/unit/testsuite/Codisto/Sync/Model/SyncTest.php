<?php
//MyCustom_Module_Test_Model_Product

class Codisto_Sync_Model_SyncTest extends EcomDev_PHPUnit_Test_Case
{

	protected $_syncObject;

	public function setUp()
	{
		/* You'll have to load Magento app in any test classes in this method */
		$app = Mage::app('default');

		$this->_syncObject = Mage::getModel('codistosync/sync');

	}

	public function testMagestore()
	{
		$this->assertEquals(1, Mage::app()->getStore()->getId());
	}

	public function testFirstTest()
	{

		$this->assertEquals(0, 2 - 2);
	}



}