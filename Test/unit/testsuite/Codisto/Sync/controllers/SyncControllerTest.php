<?php

class Codisto_Sync_Controllers_SyncControllerTest extends EcomDev_PHPUnit_Test_Case_Controller
{
	public function setUp()
	{
		/* You'll have to load Magento app in any test classes in this method */
		$app = Mage::app('default');

		/* You will need a layout for block tests */
		//$this->_layout = $app->getLayout();

	}

	public function testMagestore()
	{
		$this->assertEquals(1, Mage::app()->getStore()->getId());
	}

	public function testFirstTest()
	{

		$this->assertEquals(0, 2 - 2);
	}

	//TODO real controller tests with dispatch   http://www.ecomdev.org/wp-content/uploads/2011/05/EcomDev_PHPUnit-0.2.0-Manual.pdf page 23



}