<?php


class Codisto_Sync_Model_SyncTest extends EcomDev_PHPUnit_Test_Case
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

#Your test case class should be named in such a way:
#[Your Module]_Test_[Group Directory]_[Related Entity Name]