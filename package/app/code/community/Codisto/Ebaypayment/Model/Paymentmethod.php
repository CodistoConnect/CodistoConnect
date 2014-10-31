<?php
class Codisto_Ebaypayment_Model_Paymentmethod extends Mage_Payment_Model_Method_Abstract
{

    protected $_code  = 'ebaypayment';

	protected $_isGateway = false;
	protected $_canAuthorize = false;
	protected $_canCapture = false;
	protected $_canCapturePartial = false;
	protected $_canRefund = false;
	protected $_canVoid = false;
	protected $_canUseInternal = true;
	protected $_canUseCheckout = false;
	protected $_canUseForMultiShipping = true;
	protected $_canSaveCc = false;

}

