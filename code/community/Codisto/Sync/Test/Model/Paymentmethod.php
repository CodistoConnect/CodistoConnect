<?php

class Codisto_Sync_Test_Model_Paymentmethod extends EcomDev_PHPUnit_Test_Case
{

	protected $_syncObject;

	public function setUp()
	{
		@session_start();

		$app = Mage::app('default');

	}

	/**
	 * Test ebaypayment method is a valid payment method
	 *
	 * @test
	 */

	public function testeBayPaymentMethodExists()
	{
		//Get a list of payment methods ... confirm that eBay payment method is registered
		$payments = Mage::getSingleton('payment/config')->getActiveMethods();
		$this->assertEquals(true, array_key_exists('ebay', $payments));
	}

}
