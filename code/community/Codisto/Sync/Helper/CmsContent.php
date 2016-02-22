<?php

require_once 'app/Mage.php';

Mage::app();

try {
	echo Mage::helper('cms')->getBlockTemplateProcessor()->filter(preg_replace('/^\s+|\s+$/', '', base64_decode($argv[1])));
} catch (Exception $e) {
	echo $e->getMessage();
}
