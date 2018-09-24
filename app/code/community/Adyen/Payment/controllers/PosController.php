<?php
/**
 * Adyen Payment Module
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
 * @category    Adyen
 * @package    Adyen_Payment
 * @copyright    Copyright (c) 2011 Adyen (http://www.adyen.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * @category   Payment Gateway
 * @package    Adyen_Payment
 * @author     Adyen
 * @property   Adyen B.V
 * @copyright  Copyright (c) 2014 Adyen BV (http://www.adyen.com)
 */
class Adyen_Payment_PosController extends Mage_Core_Controller_Front_Action
{

    public function initiateAction()
    {
        $api = Mage::getSingleton('adyen/api');
        $quote = (Mage::getModel('checkout/type_onepage') !== false) ? Mage::getModel('checkout/type_onepage')->getQuote() : Mage::getModel('checkout/session')->getQuote();
        $storeId = Mage::app()->getStore()->getId();

        $adyenHelper = Mage::helper('adyen');
        $poiId = $adyenHelper->getConfigData('pos_terminal_id', "adyen_pos_cloud", $storeId);
        $serviceID = date("dHis");
        $initiateDate = date("U");
        $timeStamper = date("Y-m-d") . "T" . date("H:i:s+00:00");
        $customerId = $quote->getCustomerId();

        $reference = $quote->reserveOrderId()->getReservedOrderId();

        $request = array(
            'SaleToPOIRequest' => array(
                'MessageHeader' => array(
                    'MessageType' => 'Request',
                    'MessageClass' => 'Service',
                    'MessageCategory' => 'Payment',
                    'SaleID' => 'Magento1Cloud',
                    'POIID' => $poiId,
                    'ProtocolVersion' => '3.0',
                    'ServiceID' => $serviceID
                ),
                'PaymentRequest' => array(
                    'SaleData' => array(
                        'TokenRequestedType' => 'Customer',
                        'SaleTransactionID' => array(
                            'TransactionID' => $reference,
                            'TimeStamp' => $timeStamper
                        ),
                    ),
                    'PaymentTransaction' => array(
                        'AmountsReq' => array(
                            'Currency' => $quote->getBaseCurrencyCode(),
                            'RequestedAmount' => doubleval($quote->getGrandTotal())
                        ),
                    ),
                    'PaymentData' => array(
                        'PaymentType' => 'Normal'
                    ),
                ),
            ),
        );

        // If customer exists add it into the request to store request
        if (!empty($customerId)) {
            $shopperEmail = $quote->getCustomerEmail();
            $recurringContract = $adyenHelper->getConfigData('recurring_type', 'adyen_pos_cloud', $storeId);

            if (!empty($recurringContract) && !empty($shopperEmail) && !empty($customerId)) {
                $recurringDetails = array(
                    'shopperEmail' => $shopperEmail,
                    'shopperReference' => strval($customerId),
                    'recurringContract' => $recurringContract
                );
                $request['SaleToPOIRequest']['PaymentRequest']['SaleData']['SaleToAcquirerData'] = http_build_query($recurringDetails);
            }
        }

        $quote->getPayment()->getMethodInstance()->getInfoInstance()->setAdditionalInformation('serviceID', $serviceID);
        $quote->getPayment()->getMethodInstance()->getInfoInstance()->setAdditionalInformation('initiateDate',
            $initiateDate);

        $result = "Stop";
        // Continue only if success or timeout
        try {
            $response = $api->doRequestSync($request, $storeId);
            if (!empty($response['SaleToPOIResponse']['PaymentResponse']) && $response['SaleToPOIResponse']['PaymentResponse']['Response']['Result'] == 'Success') {
                $result = "OK";
            }
        } catch(Adyen_Payment_Exception $e) {
            if($e->getCode() == CURLE_OPERATION_TIMEOUTED) {
                $result = "Timeout";
            }
        }

        $quote->getPayment()->getMethodInstance()->getInfoInstance()->setAdditionalInformation('terminalResponse',
            $response);

        $quote->save();

        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody($result);
        return $result;
    }
}