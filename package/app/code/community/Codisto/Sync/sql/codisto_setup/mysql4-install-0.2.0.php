<?php
/**
 * Magento
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
 * @copyright   Copyright (c) 2012 On Technology (http://www.ontech.com.au)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
$installer = $this;
$installer->startSetup();
$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
$setup->addAttribute('order', 'codisto_orderid', array(
    'position'             => 1,
    'type'              => 'text',
    'label'                => 'Codisto Order ID',
    'global'            => 1,
    'visible'           => 1,
    'required'          => 0,
    'user_defined'      => 1,
    'searchable'        => 0,
    'filterable'        => 0,
    'comparable'        => 0,
    'visible_on_front'  => 1,
    'visible_in_advanced_search' => 0,
    'unique'            => 0,
    'is_configurable'   => 1
));

echo '<pre>installed!!!</pre>';

$installer->endSetup();