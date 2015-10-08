<?php
@session_start();
class Codisto_Sync_Test_Controllers_IndexController extends EcomDev_PHPUnit_Test_Case_Controller
{
	public function setUp()
	{
		$app = Mage::app('default');
	}
}
