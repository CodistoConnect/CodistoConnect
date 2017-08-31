<?php

require_once 'app/Mage.php';

Mage::app();

try {

    $contents = file_get_contents('php://stdin');

    file_put_contents('php://stdout', Mage::helper('cms')->getBlockTemplateProcessor()->filter(trim($contents))); // @codingStandardsIgnoreLine

} catch (Exception $e) {

    file_put_contents('php://stdout', $e->getMessage()); // @codingStandardsIgnoreLine

}
