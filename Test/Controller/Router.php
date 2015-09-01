<?php

class Codisto_Sync_Test_Controller_Router extends EcomDev_PHPUnit_Test_Case_Controller
{
	public function setUp()
	{
		@session_start();

		$app = Mage::app('default');

	}

	/**
	 * Test getAllHeaders returns list of headers sanitized accordingly
	 *
	 * @test
	 */

	public function testgetAllHeaders()
	{

	}

}