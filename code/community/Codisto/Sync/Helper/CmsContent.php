<?php

require_once 'app/Mage.php';

Mage::app();

try {

	$contents = file_get_contents('php://stdin');

	echo Mage::helper('cms')->getBlockTemplateProcessor()->filter(preg_replace('/^\s+|\s+$/', '', $contents));

} catch (Exception $e) {

	echo $e->getMessage();

}
