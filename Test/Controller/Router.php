<?php

class Codisto_Sync_Test_Controller_Router extends EcomDev_PHPUnit_Test_Case_Controller
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



}