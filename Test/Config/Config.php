<?php

class Codisto_Sync_Test_Config_Config extends EcomDev_PHPUnit_Test_Case_Config
{
	public function setUp()
	{
		@session_start();

		$app = Mage::app('default');

	}

	/**
	 * Test that CodistoConnect menu has been added and contains correct entries
	 *
	 * @test
	 */
	 public function testCodistoModuleCodePool()
	 {
		 $this->assertModuleCodePool("community");
	 }

	 public function testCodistoModuleDependencies()
	 {
		 $this->assertModuleDepends("Mage_Payment");
	 }



}