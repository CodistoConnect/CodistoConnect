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
* @copyright   Copyright (c) 2015 On Technology Pty. Ltd. (http://codisto.com/)
* @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*/

$MerchantID = Mage::getStoreConfig('codisto/merchantid', 0);
$HostKey = Mage::getStoreConfig('codisto/hostkey', 0);
$reindexRequired = true;

if(!isset($MerchantID) || !isset($HostKey))
{
    $request = Mage::app()->getRequest();
    $createMerchant = false;
    $path = $request->getPathInfo();

    if(!preg_match('/\/codisto-sync\//', $path))
    {
        try
        {

            if(!extension_loaded('pdo'))
            {
                throw new Exception('(PHP Data Objects) please refer to <a target="#blank" href="http://help.codisto.com/article/64-what-is-pdoexception-could-not-find-driver">Codisto help article</a>', 999);
            }

            if(!in_array("sqlite",PDO::getAvailableDrivers(), TRUE))
            {
                throw new PDOException('(sqlite PDO Driver) please refer to <a target="#blank" href="http://help.codisto.com/article/64-what-is-pdoexception-could-not-find-driver">Codisto help article</a>', 999);
            }

            //Can this request create a new merchant ?
            $createMerchant  = Mage::helper('codistosync')->createMerchantwithLock(20.0);

        }

        //Something else happened such as PDO related exception
        catch(Exception $e)
        {
            //If competing requests are coming in as the extension is installed the lock above will be held ... don't report this back to Codisto .
            if($e->getCode() != "HY000")
            {
                //Otherwise report  other exception details to Codisto regarding register
                Mage::helper('codistosync')->logExceptionCodisto($e, "https://ui.codisto.com/installed");
            }
        }

        $reindexRequired = false;

        if($createMerchant)
        {
            //If a merchant was succesfully created  (a MerchantID was returned) then re-index.
            $MerchantID = Mage::helper('codistosync')->registerMerchant($request);
            $reindexRequired = $MerchantID;
        }
    }
}

if($reindexRequired)
{
    Mage::helper('codistosync')->eBayReIndex();
}
