<?php

class Codisto_Sync_Test_Controllers_IndexController extends EcomDev_PHPUnit_Test_Case_Controller
{
	public function setUp()
	{
		@session_start();
		
		$app = Mage::app('default');

	}

}