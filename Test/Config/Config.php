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
																				"method" => "paymentInfoBlockPrepareSpecificInformation"),

			"sales_order_shipment_save_after" => array("type" => "singleton",
															"method" => "salesOrderShipmentSaveAfter"),

			"sales_order_invoice_save_commit_after" => array("namespace" => "codisto_save_invoice",
																	"method" => "salesOrderInvoiceSaveAfter"),

			"checkout_submit_all_after" => array("namespace" => "codisto_stockmovements",
														"method" => "checkoutAllSubmitAfter"),

			"cataloginventory_stock_revert_products_sale" => array("namespace" => "codisto_stockmovements",
														"method" => "stockRevertProductsSale"),

			"catalog_product_import_finish_before" => array("namespace" => "codisto_stockmovements",
														"method" => "catalogProductImportFinishBefore"),

			"sales_order_item_cancel" => array("namespace" => "codisto_stockmovements",
														"method" => "cancelOrderItem",
														"extra" => array ( array("path" => "inventory/type", "value" => "disabled"))),

			"tax_settings_change_after" => array("namespace" => "codisto_taxsync",
														"type" => "singleton",
														"method" => "taxSettingsChangeAfter"),

			"core_block_abstract_prepare_layout_after" => array("namespace" => "codisto_admin",
																		"type" => "singleton",
																		"method" => "addProductTab"),

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

			//default class
			$class = "Codisto_Sync_Model_Observer";
			if(array_key_exists("class", $value)) {
				$class = $value["class"];
			}
			$this->assertConfigNodeValue("global/events/". $key . "/observers/". $namespace . "/class",
				$class);


			$this->assertConfigNodeValue("global/events/". $key . "/observers/". $namespace . "/method",
				$value["method"]);

			//Any extra meta data to check in the nodes relative to observers child node
			if(array_key_exists("extra", $value)) {

				for($i = 0; $i < count($value["extra"]); $i ++) {

					$path = $value["extra"][$i]["path"];
					$val = $value["extra"][$i]["value"];

					$this->assertConfigNodeValue("global/events/". $key . "/observers/" . $path,
						$val);
				}
			}

		}



	}


}