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

class Codisto_Sync_CodistoController extends Mage_Adminhtml_Controller_Action
{
    public $_publicActions = array('index', 'intro', 'settings', 'orders');

    public function indexAction()
    {
        $url = preg_replace('/\/ebaytab(?:\/index)?(\/key\/)?/', '/ebaytab$1', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

        $this->renderPane($url, 'codisto-bulk-editor');
    }

    public function ordersAction()
    {
        $url = preg_replace('/\/ebaytab(?:\/index)?(\/key\/)?/', '/orders$1', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

        $action = $this->getRequest()->getQuery('action');
        if($action)
            $url = $url . '?action='. $action;

        $this->renderPane($url, 'codisto-bulk-editor');
    }

    public function categoriesAction()
    {
        $url = preg_replace('/\/ebaytab(?:\/index)?(\/key\/)?/', '/ebaytab/categories$1', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

        $this->renderPane($url, 'codisto-bulk-editor');
    }

    public function importAction()
    {
        $url = preg_replace('/\/ebaytab(?:\/index)?(\/key\/)?/', '/ebaytab/importlistings$1', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'). '?v=2');

        $this->renderPane($url, 'codisto-bulk-editor');
    }

    public function introAction()
    {
        $url = preg_replace('/\/ebaytab(?:\/index)?(\/key\/)?/', '/ebaytab$1', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab')) . '?intro=1';

        $this->renderPane($url, 'codisto-bulk-editor');
    }

    public function attributemappingAction()
    {
        $url = preg_replace('/\/ebaytab(?:\/index)?(\/key\/)?/', '/ebaytab/attributemapping$1', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

        $this->renderPane($url, 'codisto-attributemapping');
    }

    public function accountAction()
    {
        $url = preg_replace('/\/ebaytab(?:\/index)?(\/key\/)?/', '/account$1', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

        $this->renderPane($url, 'codisto-account');
    }

    public function settingsAction()
    {
        $url = preg_replace('/\/ebaytab(?:\/index)?(\/key\/)?/', '/settings$1', Mage::getModel('adminhtml/url')->getUrl('codisto/ebaytab'));

        $this->renderPane($url, 'codisto-settings');
    }

    public function registerAction()
    {
        $request = $this->getRequest();

        $form_key = Mage::getSingleton('core/session')->getFormKey();
        $registertemplate;
        $registrationerror = false;

        if ($request->isPost() ||
                $request->getQuery('action') == 'codisto_create') {

                if($request->isPost())
                {
                    $method = $request->getPost('method');
                    if($method == "email")
                    {
                        $emailaddress = $request->getPost('email');
                    }
                    else
                    {
                        $response = $this->getResponse();

                        $type = 'magento';
                        $magentoversion = Mage::getVersion();
                        $url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
                        $storeName = Mage::getStoreConfig('general/store_information/name', 0);
                        $StoreID = 0;
                        $storeCurrency = Mage::app()->getStore($StoreID)->getCurrentCurrencyCode();
                        $resellerKey = Mage::getConfig()->getNode('codisto/resellerkey');
                        if($resellerKey)
                        {
                            $resellerKey = intval(trim((string)$resellerKey));
                        }
                        else
                        {
                            $resellerKey = '0';
                        }
                        $codistoVersion = Mage::getConfig()->getNode()->modules->Codisto_Sync->version;

                        $this->_redirectUrl(
                                'https://ui.codisto.com/register?finalurl='.
                                urlencode(Mage::helper('core/url')->getCurrentUrl().'?action=codisto_create').
                                '&type='.urlencode($type).
                                '&version='.urlencode($magentoversion).
                                '&url='.urlencode($url).
                                '&storename='.urlencode($storeName).
                                '&storecurrency='.urlencode($storeCurrency).
                                '&resellerkey='.urlencode($resellerKey).
                                '&codistoversion='.urlencode($codistoVersion)
                        );
                    }
                }
                else
                {
                    // eBay auth return
                    $method = "ebay";
                    $regtoken = $request->getQuery('regtoken');
                }

                try
                {
                    $merchantID = null;
                    $createMerchant = false;
                    try
                    {
                        $createMerchant = Mage::helper('codistosync')->createMerchantwithLock(5.0);
                    }
                    //Something else happened such as PDO related exception
                    catch(Exception $e)
                    {
                        // Report exception details to Codisto regarding register
                        Mage::helper('codistosync')->logExceptionCodisto($e, "https://ui.codisto.com/installed");
                        throw $e;
                    }
                    if($createMerchant)
                    {
                        $merchantID = Mage::helper('codistosync')->registerMerchant($method, $emailaddress, $regtoken);
                    }
                    if($merchantID)
                    {
                        $merchantID = Zend_Json::decode($merchantID);
                        $this->_redirectUrl(Mage::getModel('adminhtml/url')->getUrl('/codisto/index'));
                    }
                }
                catch(Exception $e)
                {
                    if($e->getCode() == 999)
                    {
                        $registrationerror = true;
                        $registrationerrortext = <<<EOT
                        <h1>Unable to Register</h1><p>Sorry, we were unable to register your Codisto account,
                        your Magento installation is missing a required Pre-requisite' . $e->getMessage() .
                        ' or contact <a href="mailto:support@codisto.com">support@codisto.com</a> and our team will help to resolve the issue</p>
EOT;
                    }
                    else
                    {
                        $registrationerror = true;
                        $registrationerrortext = <<<EOT
                        <h1>Unable to Register</h1><p>Sorry, we are currently unable to register your Codisto account.
                        In most cases, this is due to your server configuration being unable to make outbound communication to the Codisto servers.</p>
                        <p>This is usually easily fixed - please contact <a href="mailto:support@codisto.com">support@codisto.com</a> and our team will help to resolve the issue</p>
EOT;
                    }
                }
                if($merchantID == null)
                {
                    $registrationerror = true;
                    $registrationerrortext = <<<EOT
                    <h1>Unable to Register</h1><p>Sorry, we are currently unable to register your Codisto account.
                    In most cases, this is due to your server configuration being unable to make outbound communication to the Codisto servers.</p>
                    <p>This is usually easily fixed - please contact <a href="mailto:support@codisto.com">support@codisto.com</a> and our team will help to resolve the issue</p>
EOT;
                }
        }

        if(!extension_loaded('pdo'))
        {
            $registrationerror = true;
            $registrationerrortext = <<<EOT
            <h1>Prerequisite Error</h1>
            <h2>(PHP Data Objects is missing)</h2>
            <p>Please refer to <a target="#blank" href="https://get.codisto.help/hc/en-us/articles/235261667-What-is-PDOException-could-not-find-driver-">Codisto help article - What is PDOException : could not find driver?</a></p>
EOT;
        }

        if(!in_array("sqlite",PDO::getAvailableDrivers(), TRUE))
        {
            $registrationerror = true;
            $registrationerrortext = <<<EOT
            <h1>Prerequisite Error</h1>
            <h2>(SQLite PDO Driver is missing)</h2>
            <p>Please refer to <a target="#blank" href="https://get.codisto.help/hc/en-us/articles/235261667-What-is-PDOException-could-not-find-driver-">Codisto help article - What is PDOException : could not find driver?</a></p>
EOT;
        }

        if($registrationerror)
        {
            $registertemplate = <<<EOT
            <style>

            #registration-error-modal
            {
                box-sizing: content-box;
                position: absolute;
                z-index: 2;
                background-color: rgba(255,255,255,0.9);
                top: 30px;
                width: 600px;
                margin-left: auto;
                margin-right: auto;
                left: 0;
                right: 0;
                box-shadow: 0px 5px 15px rgba(0,0,0,0.5);
                font-family: Roboto;
            }

            #registration-error-modal H1
            {
                margin: 0px;
                background-color: rgba(255,255,255,0.9);
                padding: 6px;
                padding-top: 20px;
                padding-bottom: 10px;
                height: 36px;
                border-bottom: 1px solid #e5e5e5;
            }

            #registration-error-modal H2
            {
                margin: 0px;
                background-color: rgba(255,255,255,0.9);
                padding: 6px;
                padding-top: 5px;
                padding-bottom: 10px;
                height: 36px;
                font-weight: 500;
            }

            #registration-error-modal P
            {
                margin: 0px;
                background-color: rgba(255,255,255,0.9);
                padding: 6px;
                padding-top: 5px;
                padding-bottom: 30px;
                height: 36px;
            }

            #dummy-data-overlay
            {
                position: absolute;
                z-index: 1;
                top: 0px;
                left: 0px;
                bottom: 0px;
                right: 0px;
                background-color: rgba(0,0,0,0.85);
            }

            #dummy-data
            {
                position: absolute; z-index: 0; top: 0px; left: 0px; bottom: 0px; right: 0px; width:100%; height:100%
            }
            </style>
            <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:500,900,700,400">
            <iframe id="dummy-data" frameborder="0" src="https://codisto.com/xpressgriddemo/ebayedit/"></iframe>
            <div id="dummy-data-overlay"></div>
            <div id="registration-error-modal">
            $registrationerrortext
            </div>
EOT;
        }
        else
        {
            Mage::getModel("admin/user");
            $session = Mage::getSingleton('admin/session');
            // Get the user object from the session
            $user = $session->getUser();
            if(!$user)
            {
                $user = Mage::getModel('admin/user')->getCollection()->setPageSize(1)->setCurPage(1)->getFirstItem();
            }
            $email = $user->getEmail();

            $registertemplate = <<<EOT
            <style>

            #create-account-modal
            {
                    box-sizing: content-box;
                    position: absolute;
                    z-index: 2;
                    background-color: transparent;
                    top: 30px;
                    width: 600px;
                    margin-left: auto;
                    margin-right: auto;
                    left: 0;
                    right: 0;
                    box-shadow: 0px 5px 15px rgba(0,0,0,0.5);
                    font-family: Roboto;
            }

            #create-account-modal H1
            {
                    margin: 0px;
                    background-color: rgba(255,255,255,0.9);
                    padding: 6px;
                    padding-top: 34px;
                    padding-bottom: 10px;
                    height: 36px;
                    padding-left: 15px;
                    font-weight: 500;
                    border-bottom: 1px solid #e5e5e5;
            }


            #create-account-modal .option
            {
                    border: 1px solid #ccc;
                    margin-top: 1px;
                    margin-bottom: 1px;
                    cursor: pointer;
                    padding: 22px;
                    width: 80%;
                    margin-left: auto;
                    margin-right: auto;
            }

            #create-account-modal .option INPUT[type=radio]
            {
                    vertical-align: top;
                    margin-top: 3px;
            }

            #create-account-modal .option.active
            {
                    border: 2px solid #009;
                    margin-top: 0px;
                    margin-bottom: 0px;
            }

            #create-account-modal FORM
            {
                    background-color: #fff;
                    padding-top: 20px;
                    padding-bottom: 20px;
            }

            #create-account-modal .or
            {
                    padding: 20px;
                    text-align: center;
            }

            #create-account-modal .next
            {
                    padding: 20px; text-align: right; width: 87%; margin-left: auto; margin-right: auto; padding-bottom: 2px;
            }

            #create-account-modal .next .button
            {
                    width: 80px;
            }

            #create-account-modal .footer
            {
                    padding: 15px;
                    background-color: rgba(255,255,255,0.9);
                    border-top: 1px solid #e5e5e5;
            }

            #create-account-modal .footer UL
            {
                    list-style-type: circle; margin-left: 40px;
            }

            #dummy-data-overlay
            {
                    position: absolute;
                    z-index: 1;
                    top: 0px;
                    left: 0px;
                    bottom: 0px;
                    right: 0px;
                    background-color: rgba(0,0,0,0.85);
            }

            #dummy-data
            {
                    position: absolute; z-index: 0; top: 0px; left: 0px; bottom: 0px; right: 0px; width:100%; height:100%
            }

            </style>
            <script src="https://ui.codisto.com/js/jquery-3.1.1.min.js"></script>
            <script>
            $( document ).ready(function() {

                $("#create-account-modal").on("click", ".option", function (e) {

                    $("#create-account-modal .option").removeClass("active");
                    $(this).addClass("active").find("INPUT[type=radio]").attr("checked", "checked");

                });
            });
            </script>
            <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:500,900,700,400">
            <iframe id="dummy-data" frameborder="0" src="https://codisto.com/xpressgriddemo/ebayedit/"></iframe>
            <div id="dummy-data-overlay"></div>
            <div id="codisto-control-panel-wrapper">
                <div id="create-account-modal">
                    <h1>Codisto Connect - Account Creation</h1>
                    <form method="post" target="_top">
                        <input type="hidden" name="action" value="codisto_create"/>
                        <input type="hidden" name="form_key" value="$form_key"/>

                        <div class="option active">
                            <label>
                                <input type="radio" name="method" checked="checked" value="ebay">
                                <div style="display: inline-block;">
                                    <img style="height: 20px;" src="https://d31wxntiwn0x96.cloudfront.net/connect/29137/ebaytab/images/ebay.png" scale="0">
                                    <div style="padding-top: 6px;">Link your eBay account to create an account automatically</div>
                                </div>
                            </label>
                        </div>

                        <div class="or">
                        <strong>OR</strong>
                        </div>

                        <div class="option">
                            <label>
                                <input type="radio" name="method" value="email">
                                <div style="display: inline-block;">
                                    <input type="text" name="email" value="$email" size="40">
                                    <div style="padding-top: 10px;">Use your email address (you can link eBay later)</div>
                                </div>
                            </label>
                        </div>

                        <div class="next">
                            <button class="button button-primary">Next</button>
                        </div>

                    </form>
                    <div class="footer">
                            Once you create an account we will begin synchronizing your catalog data.<br>
                            Sit tight, this may take several minutes depending on the size of your catalog.<br>
                            When completed, you'll have the world's best Amazon & eBay integration at your fingertips.<br><br/>
                            You'll be able to:
                                <ul>
                                    <li>Sync in real-time between Magento and Amazon &amp; eBay</li>
                                    <li>have Codisto auto-categorize your products into eBay categories</li>
                                    <li>Access our sophisticated template engine for amazing listings</li>
                                    <li>and lots more…</li>
                                </ul>
                    </div>
                </div>
            </div>
EOT;
        }

        echo $registertemplate;
    }

    private function renderPane($url, $class)
    {
        $this->loadLayout();

        $block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<div id="codisto-control-panel-wrapper"><iframe id="codisto-control-panel" class="codisto-iframe '. htmlspecialchars($class) .'" src="'. htmlspecialchars($url) . '" frameborder="0" onmousewheel=""></iframe></div>');
        $this->_addContent($block);

        $this->renderLayout();
    }

    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('codisto');
    }
}
