<?php


class Codisto_Sync_Model_SyncTest extends EcomDev_PHPUnit_Test_Case
{

	protected $_syncObject;

	public function setUp()
	{

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