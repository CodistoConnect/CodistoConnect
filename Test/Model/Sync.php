<?php

class Codisto_Sync_Test_Model_Sync extends EcomDev_PHPUnit_Test_Case
{

	protected $_syncObject;

	public function setUp()
	{
		@session_start();
		#Suppress errors for Cannot send session cookie - headers already sent PHPUnit
		#parent::setUp();

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

