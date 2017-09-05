<?php

require_once 'app/Mage.php';

Mage::app();

try {

    $contents = file_get_contents('php://stdin');

    file_put_contents('php://output', Mage::helper('cms')->getBlockTemplateProcessor()->filter(trim($contents))); // @codingStandardsIgnoreLine

} catch (Exception $e) {

    file_put_contents('php://output', $e->getMessage()); // @codingStandardsIgnoreLine

}
