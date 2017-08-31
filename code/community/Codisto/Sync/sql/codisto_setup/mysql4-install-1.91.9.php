<?php
/**
 * Codisto eBay Sync Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category    Codisto
 * @package     Codisto_Sync
 * @copyright   Copyright (c) 2014 On Technology Pty. Ltd. (http://codisto.com/)
 * @license     http://opensource.org/licenses/osl-3.0.php    Open Software License (OSL 3.0)
 */
$installer = $this;
$installer->startSetup();

$connection = $this->getConnection();

$tablename = $prefix = Mage::getConfig()->getTablePrefix() . 'sales_flat_order';

$connection->addColumn(
    $tablename,
    'codisto_orderid',
    'varchar(10)'
);

$connection->addColumn(
    $tablename,
    'codisto_merchantid',
    'varchar(10)'
);

$installer->endSetup();
