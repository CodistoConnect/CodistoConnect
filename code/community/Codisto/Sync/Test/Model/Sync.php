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

	/*
		Placeholder test to silence warnings about no tests in this file
	*/
	public function testSyncStub()
	{
		$this->assertEquals(true, true);
	}

}

