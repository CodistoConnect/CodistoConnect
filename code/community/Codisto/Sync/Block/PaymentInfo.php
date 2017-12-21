<?php
/**
 * Codisto eBay & Amazon Sync Extension
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

class Codisto_Sync_Block_PaymentInfo extends Mage_Payment_Block_Info
{
    public function toPdf()
    {
        $html = $this->escapeHtml($this->getMethod()->getTitle());

        $specificInfo = $this->getSpecificInformation();

        $html .= '{{pdf_row_separator}}';

        foreach($specificInfo as $k => $v)
        {
            if(!preg_match('/_HTML$/', $k))
            {
                $html .= $k.': '.$v.'{{pdf_row_separator}}';
            }
        }

        return $html;
    }

    protected function _toHtml()
    {
        $secureMode = $this->getIsSecureMode();

        $html = $this->escapeHtml($this->getMethod()->getTitle());

        $specificInfo = $this->getSpecificInformation();

        $html .= '<table class="codisto-payment-info">';

        foreach($specificInfo as $k => $v)
        {
            if($secureMode)
            {
                if(!preg_match('/_HTML$/', $k))
                {
                    $html .= '<tr><td>'.$this->escapeHtml($k).'</td><td>'.$this->escapeHtml($v).'</td></tr>';
                }
            }
            else
            {
                if(preg_match('/_HTML$/', $k))
                {
                    $html .= '<tr><td>'.$this->escapeHtml(preg_replace('/_HTML$/', '', $k)).'</td><td>'.$v.'</td></tr>';
                }
            }
        }

        $html .= '</table>';

        return $html;
    }
}
