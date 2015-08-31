<?php

class Codisto_Sync_Test_Controllers_SyncController extends EcomDev_PHPUnit_Test_Case_Controller
{
	public function setUp()
	{
		@session_start();


		$app = Mage::app('default');

	}

	public function testMagestore()
	{
		$this->assertEquals(0, Mage::app()->getStore()->getId());
	}

	public function testFirstTest()
	{

		$this->assertEquals(0, 2 - 2);
	}

	//TODO real controller tests with dispatch   http://www.ecomdev.org/wp-content/uploads/2011/05/EcomDev_PHPUnit-0.2.0-Manual.pdf page 23



}