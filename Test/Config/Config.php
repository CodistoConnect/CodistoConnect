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

	/**
	 * Test that CodistoConnect configuration dependencies are specified correctly
	 *
	 * @test
	 */
	 public function testCodistoModuleDependencies()
	 {
		 $this->assertModuleDepends("Mage_Payment");
	 }

	/**
	 * Test that the current version is >= 1.1.25 (when unit tests were first added)
	 *
	 * @test
	 */
	 public function testCodistoConnectVersion()
	 {
		 $this->assertModuleVersionGreaterThanOrEquals("1.1.25");
	 }

	/**
	 * Test aliases are in place
	 *
	 * @test
	 */
	public function testAliases()
	{

		//Model Aliases
		$this->assertModelAlias("codistosync/codisto_sync_model", "Codisto_Sync_Model_Codisto_Sync_Model");
		$this->assertModelAlias("ebaypayment/codisto_sync_ebaypayment_model", "Codisto_Sync_Ebaypayment_Model_Codisto_Sync_Ebaypayment_Model");

		//Helper Aliases
		$this->assertHelperAlias("codisto-sync/codisto_sync_helper", "Codisto_Sync_Helper_Codisto_Sync_Helper");


		//Resource Model Aliases
		//$this->assertResourceModelAlias("codisto_setup/codisto_sync_model_resource_mysql4_setup", "Codisto_Sync_Model_Resource_Mysql4_Codisto_Sync_Model_Resource_Mysql4_Setup");

	}

	/**
	 * Test events are wired up correctly
	 *
	 * @test
	 */
	public function testEventNodes()
	{

		$events = array(
			"payment_info_block_prepare_specific_information" => array("type" => "singleton",
																				"class" => "Codisto_Sync_Model_Observer",
																				"method" => "paymentInfoBlockPrepareSpecificInformation"),
			"sales_order_shipment_save_after" => array("type" => "singleton",
															"class" => "Codisto_Sync_Model_Observer",
															"method" => "salesOrderShipmentSaveAfter"),
			"sales_order_invoice_save_commit_after" => array("namespace" => "codisto_save_invoice",
				"class" => "Codisto_Sync_Model_Observer",
				"method" => "salesOrderInvoiceSaveAfter"),
		);

		//Assert type, class and method values are expected
		foreach ($events as $key => $value) {

			$namespace = "codisto";
			if(array_key_exists("namespace", $value)) {
				$namespace = $value["namespace"];
			}

			if(array_key_exists("type", $value)) {
				$this->assertConfigNodeValue("global/events/" . $key . "/observers/". $namespace . "/type",
					$value["type"]);
			}

			$this->assertConfigNodeValue("global/events/". $key . "/observers/". $namespace . "/class",
				$value["class"]);


			$this->assertConfigNodeValue("global/events/". $key . "/observers/". $namespace . "/method",
				$value["method"]);


		}

	}


}