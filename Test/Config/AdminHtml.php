<?php

class Codisto_Sync_Test_Config_AdminHtml extends EcomDev_PHPUnit_Test_Case_Config
{
	public function setUp()
	{
		@session_start();

		$app = Mage::app('default');

	}

	/**
	 * Test that adminhtml.xml layout file is present
	 *
	 * @test
	 */

	public function testLayoutPresent()
	{
		//$this->assertLayoutFileDefined("adminhtml", "adminhtml.xml");

		/*
			assertLayoutFileDefined() asserts that configuration has definition of the layout file
		◦ string $area the area of layout file. Possible values are frontend and adminhtml
		◦ string $expectedFileName expected layout file name, for instance catalog.xml
		◦ string $layoutUpdate if layout update name is specified, then it will restrict
		assertion by it. [optional]

		*/
	}


	/**
	 * Test that CodistoConnect menu has been added and contains correct entries
	 *
	 * @test
	 */
	public function testCodistoConnectMenu()
	{


		/*
		<menu>
		<codisto translate="title">
			<title>eBay | Codisto</title>
			<sort_order>99</sort_order>
			<children>
				<subitem translate="title">
					<title>Manage Listings</title>
					<sort_order>1</sort_order>
					<action>adminhtml/codisto/index</action>
				</subitem>
				<subitem1 translate="title">
					<title>Getting Started</title>
					<sort_order>2</sort_order>
					<action>adminhtml/codisto/intro</action>
				</subitem1>
				<subitem2 translate="title">
					<title>Settings</title>
					<sort_order>3</sort_order>
					<action>adminhtml/codisto/settings</action>
				</subitem2>
			</children>
		</codisto>
		</menu>
		*/
	}




}