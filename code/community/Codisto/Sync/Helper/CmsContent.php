<?php

require_once 'app/Mage.php';

Mage::app();

try {

	$contents = file_get_contents('php://stdin');

	echo Mage::helper('cms')->getBlockTemplateProcessor()->filter(trim($contents)); // @codingStandardsIgnoreLine

} catch (Exception $e) {

	echo $e->getMessage(); // @codingStandardsIgnoreLine

}
