<?php

class Codisto_Sync_Test_Controllers_CodistoController extends EcomDev_PHPUnit_Test_Case_Controller
{
	public function setUp()
	{
		@session_start();

		$app = Mage::app('default');

	}

	public function testIntroAction()
	{
		$this->dispatch('adminhtml/codisto/intro');

		//Make sure there is an endpoint
		$this->assertRequestRoute('adminhtml/codisto/intro');

	}

	public function testSettingsAction()
	{
		$this->dispatch('adminhtml/codisto/settings');
		$this->assertRequestRoute('adminhtml/codisto/settings');
		$this->assertResponseBodyContains('eBay Account');
	}

	public function testIndexAction()
	{

		$this->dispatch('adminhtml/codisto/index');

		//Make sure there is an endpoint
		$this->assertRequestRoute('adminhtml/codisto/index');

	}
}