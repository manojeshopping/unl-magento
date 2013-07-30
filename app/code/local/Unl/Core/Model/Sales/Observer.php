<?php

class Unl_Core_Model_Sales_Observer
{
    /**
     * An event observer for the <code>sales_quote_item_set_product</code> event.
     * Sets the quote_item's source store view and warehose
     *
     * @param Varien_Event_Observer $observer
     */
    public function onSalesQuoteItemSetProduct($observer)
    {
        $product = $observer->getEvent()->getProduct();
        $quote_item = $observer->getEvent()->getQuoteItem();

        $attributes = Mage::getSingleton('sales/quote_config')->getSetProductAttributes();

        foreach ($attributes as $key) {
            $quote_item->setData($key, $product->getData($key));
        }
    }

    /**
     * An event observer for the <code>sales_convert_quote_item_to_order_item</code> event.
     * Adds some missing data members of quote_address_item to order_item from quote_item
     *
     * @param Varien_Event_Observer $observer
     */
    public function onSalesConvertQuoteItemToOrderItem($observer)
    {
        $item = $observer->getEvent()->getItem();
        $orderItem = $observer->getEvent()->getOrderItem();

        if ($item instanceof Mage_Sales_Model_Quote_Address_Item) {
            $attributes = Mage::getSingleton('sales/quote_config')->getAddressItemParentAttributes();
            $quoteItem = $item->getQuoteItem();

            foreach ($attributes as $key) {
                $orderItem->setData($key, $quoteItem->getData($key));
            }
        }
    }

    /**
     * An event observer for the custom <code>sales_quote_address_set_shipping_method_after</code>
     * event.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onShippingMethodSet($observer)
    {
        /* @var $address Unl_Core_Model_Sales_Quote_Address */
        $address = $observer->getEvent()->getQuoteAddress();
        $previous = $observer->getEvent()->getPrevious();
        $method = $address->getShippingMethod();

        if ($previous == $method) {
            return;
        }

        if (!empty($method)) {
            if (strpos($method, 'pickup_store') === 0) {
                Mage::getModel('unl_core/shipping_carrier_pickup')->updateAddress($address);
            } elseif (strpos($previous, 'pickup_store') === 0) {
                $billing = $address->getQuote()->getBillingAddress();
                Mage::getModel('unl_core/shipping_carrier_pickup')->revertAddress($address, $billing);
            }
        }
    }

    /**
     * An event observer for the custom <code>shipping_carrier_request_to_shipment</code>
     * event.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onBeforeRequestToShipment($observer)
    {
        /* @var $request Mage_Shipping_Model_Shipment_Request */
        /* @var $carrier Mage_Shipping_Model_Carrier_Abstract */
        /* @var $result  Varien_Object */
        $request = $observer->getEvent()->getRequest();
        $carrier = $observer->getEvent()->getCarrier();
        $result  = $observer->getEvent()->getResult();

        $session = Mage::getSingleton('adminhtml/session');

        $ackExpiration = $session->getOrderShipmentAckExp();
        if ($ackExpiration && $ackExpiration <= time()) {
            $session->getOrderShipmentAckExp(true);
            $session->getLastOrderShipmentAck(true);
        }

        $lastOrderAck = $session->getLastOrderShipmentAck(true);
        if ($lastOrderAck === $request->getOrderShipment()->getOrderId()) {
            return;
        }

        $pickup = Mage::getModel('unl_core/shipping_carrier_pickup');
        $replacementAddr = $pickup->getReplacementAddress($request->getStoreId());
        $replacementAddr['region_code'] = Mage::getModel('directory/region')->load($replacementAddr['region_id'])->getCode();

        if ($request->getRecipientContactCompanyName() == $replacementAddr['company']
            && $request->getRecipientAddressStreet1() == $replacementAddr['street'][0]
            && $request->getRecipientAddressStreet2() == $replacementAddr['street'][1]
            && $request->getRecipientAddressCity() == $replacementAddr['city']
            && $request->getRecipientAddressStateOrProvinceCode() == $replacementAddr['region_code']
            && strpos($request->getRecipientAddressPostalCode(), $replacementAddr['postcode']) === 0
            && $request->getRecipientAddressCountryCode() == $replacementAddr['country_id']
            && $request->getRecipientContactPhoneNumber() == $replacementAddr['telephone']
        ) {
            $result->setError(Mage::helper('unl_core')->__('You are about to create a label for the "internal pickup" address. Are you sure this is correct?'));
            $session->setOrderShipmentAckExp(time() + (5 * 60));
            $session->setLastOrderShipmentAck($request->getOrderShipment()->getOrderId());
        }
    }

    /**
     * An event observer for the <code>sales_order_save_after</code> event.
     * Saves tax information. Overrides main observer from tax module.
     * @see Mage_Tax_Model_Observer::salesEventOrderAfterSave()
     *
     * @param Varien_Event_Observer $observer
     */
    public function onAfterOrderSave($observer)
    {
        $order = $observer->getEvent()->getOrder();

        if (!$order->getConvertingFromQuote() || $order->getAppliedTaxIsSaved()) {
            return;
        }

        $getTaxesForItems   = $order->getQuote()->getTaxesForItems();
        $taxes              = $order->getAppliedTaxes();

        $ratesIdQuoteItemId = array();
        if (!is_array($getTaxesForItems)) {
            $getTaxesForItems = array();
        }
        foreach ($getTaxesForItems as $quoteItemId => $taxesArray) {
            foreach ($taxesArray as $rates) {
                if (count($rates['rates']) == 1) {
                    $ratesIdQuoteItemId[$rates['id']][] = array(
                        'id'        => $quoteItemId,
                        'percent'   => $rates['percent'],
                        'code'      => $rates['rates'][0]['code']
                    );
                } else {
                    $percentDelta   = $rates['percent'];
                    $percentSum     = 0;
                    foreach ($rates['rates'] as $rate) {
                        $ratesIdQuoteItemId[$rates['id']][] = array(
                            'id'        => $quoteItemId,
                            'percent'   => $rate['percent'],
                            'code'      => $rate['code']
                        );
                        $percentSum += $rate['percent'];
                    }

                    if ($percentDelta != $percentSum) {
                        $delta = $percentDelta - $percentSum;
                        foreach ($ratesIdQuoteItemId[$rates['id']] as &$rateTax) {
                            if ($rateTax['id'] == $quoteItemId) {
                                $rateTax['percent'] = (($rateTax['percent'] / $percentSum) * $delta)
                                        + $rateTax['percent'];
                            }
                        }
                    }
                }
            }
        }

        foreach ($taxes as $id => $row) {
            foreach ($row['rates'] as $tax) {
                if (is_null($row['percent'])) {
                    $baseRealAmount = $row['base_amount'];
                } else {
                    if ($row['percent'] == 0 || $tax['percent'] == 0) {
                        $baseRealAmount = 0;
                    } else {
                        $baseRealAmount = $row['base_amount'] / $row['percent'] * $tax['percent'];
                    }
                }
                $hidden = (isset($row['hidden']) ? $row['hidden'] : 0);
                $data = array(
                    'order_id'          => $order->getId(),
                    'code'              => $tax['code'],
                    'title'             => $tax['title'],
                    'hidden'            => $hidden,
                    'percent'           => $tax['percent'],
                    'priority'          => $tax['priority'],
                    'position'          => $tax['position'],
                    'amount'            => $row['amount'],
                    'base_amount'       => $row['base_amount'],
                    'process'           => $row['process'],
                    'base_real_amount'  => $baseRealAmount,
                    'sale_amount'       => $row['sale_amount'],
                    'base_sale_amount'  => $row['base_sale_amount'],
                );

                $result = Mage::getModel('tax/sales_order_tax')->setData($data)->save();

                if (isset($ratesIdQuoteItemId[$id])) {
                    foreach ($ratesIdQuoteItemId[$id] as $quoteItemId) {
                        if ($quoteItemId['code'] == $tax['code']) {
                            $item = $order->getItemByQuoteItemId($quoteItemId['id']);
                            if ($item) {
                                $data = array(
                                    'item_id'       => $item->getId(),
                                    'tax_id'        => $result->getTaxId(),
                                    'tax_percent'   => $quoteItemId['percent']
                                );
                                Mage::getModel('tax/sales_order_tax_item')->setData($data)->save();
                            }
                        }
                    }
                }
            }
        }

        $order->setAppliedTaxIsSaved(true);
    }

    /**
     * An event observer for the <code>sales_order_creditmemo_refund</code> event.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onSalesOrderCreditmemoRefund($observer)
    {
        $creditmemo = $observer->getEvent()->getCreditmemo();
        $creditmemo->setRefundedAt(Mage::getSingleton('core/date')->gmtDate());
    }

    /**
     * An <i>adminhtml</i> observer for the <code>adminhtml_sales_order_creditmemo_register_before</code>
     * event.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onSalesOrderCreditmemoRegister($observer)
    {
        $creditmemo = $observer->getEvent()->getCreditmemo();

        foreach ($creditmemo->getAllItems() as $creditmemoItem) {
            $orderItem = $creditmemoItem->getOrderItem();

            if ($creditmemoItem->getQty() && !$orderItem->isDummy()) {
                if ($orderItem->getBaseRowTotal() == 0 && !$creditmemo->getAllowZeroGrandTotal()) {
                    $creditmemo->setAllowZeroGrandTotal(true);
                }
            }
        }
    }

    /**
     * And event observer for the <code>sales_order_invoice_pay</code>
     *
     * @param Varien_Event_Observer $observer
     */
    public function onSalesOrderInvoicePay($observer)
    {
        $invoice = $observer->getEvent()->getInvoice();
        $invoice->setPaidAt(Mage::getSingleton('core/date')->gmtDate());
    }

    /**
     * An event observer for the <code>sales_order_shipment_resource_init_virtual_grid_columns</code>
     * event.
     *
     * @param Varien_Event_Observer $observer
     */
    public function initShipmentGridVirtualColumns($observer)
    {
        /* @var $resource Mage_Sales_Model_Resource_Order_Shipment */
        $resource = $observer->getEvent()->getResource();
        $resource
            ->addVirtualGridColumn(
                'shipping_description',
                'sales/order',
                array('order_id' => 'entity_id'),
                'shipping_description'
            )
            ->addVirtualGridColumn(
                'base_shipping_amount',
                'sales/order',
                array('order_id' => 'entity_id'),
                'base_shipping_amount'
            )
            ->addVirtualGridColumn(
                'shipping_amount',
                'sales/order',
                array('order_id' => 'entity_id'),
                'shipping_amount'
            );
    }

    /**
     * An event observer for the following events:
     * <ul>
     *   <li><code>sales_order_item_save_before</code></li>
     *   <li><code>sales_creditmemo_item_save_before</code></li>
     *   <li><code>sales_invoice_item_save_before</code></li>
     *   <li><code>sales_shipment_item_save_before</code></li>
     * </ul>
     *
     * @param Varien_Event_Observer $observer
     */
    public function onBeforeSalesItemSave($observer)
    {
        $item = $observer->getEvent()->getDataObject();

        switch (true) {
            case $item instanceof Mage_Sales_Model_Order_Item:
                if (!$item->hasIsDummy()) {
                    $item->setIsDummy($item->isDummy());
                }
                break;
            case $item instanceof Mage_Sales_Model_Order_Invoice_Item:
            case $item instanceof Mage_Sales_Model_Order_Creditmemo_Item:
                if (!$item->hasIsDummy()) {
                    $item->setIsDummy($item->getOrderItem()->isDummy());
                }
                break;
            case $item instanceof Mage_Sales_Model_Order_Shipment_Item:
                if (!$item->hasIsDummy()) {
                    $item->setIsDummy($item->getOrderItem()->isDummy(true));
                }
                break;
        }
    }

    /**
     * A <i>frontend</i> event observer for the <code>sales_quote_item_qty_set_after</code>
     * event.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onAfterSetSalesQuoteItemQty($observer)
    {
        $_item = $observer->getEvent()->getItem();
        $helper = Mage::helper('unl_core');
        $helper->checkCustomerAllowedProduct($_item);
        $helper->checkCustomerAllowedProductQty($_item);
    }

    /**
     * An <i>adminhtml</i> event observer for the <code>sales_order_shipment_save_before</code>
     * event.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onBeforeOrderShipmentSave($observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        if ($shipment->isObjectNew()) {
            $shipment->setObjectWasNew(true);
        }
    }

    /**
     * Enter description here ...
     *
     * @param array $items
     * @param int $orderItemId
     * @return Mage_Sales_Model_Order_Shipment_Item
     */
    protected function _getShipmentItemByOrderItemId($items, $orderItemId)
    {
        foreach ($items as $item) {
            if ($item->getOrderItemId() == $orderItemId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * An <i>adminhtml</i> event observer for the <code>sales_order_shipment_save_after</code>
     * event.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onAfterOrderShipmentSave($observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        /* @var $shipment Mage_Sales_Model_Order_Shipment */

        if ($shipment->getObjectWasNew()) {
            $shipment->unsObjectWasNew();
            $request = Mage::app()->getRequest();
            $order = $shipment->getOrder();
            $data = $request->getParam('shipment');
            $captureCase = isset($data['capture_case']) ? $data['capture_case'] : null;
            $helper = Mage::helper('unl_core/adminhtml_sales_workflow');

            if (!empty($data['do_invoice'])) {

                if ($helper->canInvoice($order)) {
                    $savedQtys = array();
                    if ($order->getPayment()->canCapturePartial()) {
                        foreach ($shipment->getAllItems() as $item) {
                            if (!$item->getOrderItem()->isDummy()) {
                                if ($item->getOrderItem()->isDummy(true)) {
                                    $parentOrderItem = $item->getOrderItem()->getParentItem();
                                    $savedQtys[$item->getOrderItemId()] = $item->getOrderItem()->getQtyOrdered()
                                        / $parentOrderItem->getQtyOrdered()
                                        * $this->_getShipmentItemByOrderItemId(
                                            $shipment->getAllItems(),
                                            $parentOrderItem->getId()
                                        )->getQty();
                                } else {
                                    $savedQtys[$item->getOrderItemId()] = $item->getQty();
                                }
                            }
                        }

                        foreach ($order->getAllItems() as $item) {
                            if (!isset($savedQtys[$item->getId()]) && $item->getQtyToInvoice()) {
                                $savedQtys[$item->getId()] = 0;
                            }
                        }
                    }

                    /* @var $invoice Mage_Sales_Model_Order_Invoice */
                    $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice($savedQtys);

                    if (!empty($captureCase)) {
                        $invoice->setRequestedCaptureCase($captureCase);
                    }

                    try {
                        $invoice->register();
                        $invoice->save();
                    } catch (Exception $e) {
                        Mage::logException($e);
                        throw $e;
                    }

                    try {
                        $invoice->sendEmail(false);
                    } catch (Exception $e) {
                        Mage::logException($e);
                    }
                } elseif ($helper->hasInvoiceNeedsCapture($order)) {
                    foreach ($order->getInvoiceCollection() as $invoice) {
                        if ($invoice->canCapture()) {
                            if ($captureCase == Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE) {
                                $invoice->capture()->save();
                            } elseif ($captureCase == Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE) {
                                $invoice->setCanVoidFlag(false);
                                $invoice->pay()->save();
                            }
                        }
                    }
                }
            }
        }

        return $this;
    }

    /**
     * An event observer for the <code>sales_order_place_after</code> event.
     * It invoices/captures all virtual products, if the payment action was AUTHORIZE.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onAfterPlaceOrder($observer)
    {
        /* @var $order Mage_Sales_Model_Order */
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        $paymentMethod = $payment->getMethodInstance();
        $action = $paymentMethod->getConfigPaymentAction();

        if ($action == Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE) {
            $authTransaction = $payment->getCreatedTransaction();
            $payment->setParentTransactionId($authTransaction->getTxnId());

            if ($order->getIsVirtual()) {
                $payment->capture(null);
                $this->_onAfterAutoCapture($payment, $authTransaction);
                return;
            }

            $hasVirtual = false;
            $savedQtys = array();
            /* @var $item Mage_Sales_Model_Order_Item */
            foreach ($order->getAllItems() as $item) {
                if ($item->getIsVirtual()) {
                    $hasVirtual = true;
                    $savedQtys[$item->getId()] = $item->getQtyToInvoice();
                } else {
                    $savedQtys[$item->getId()] = 0;
                }
            }

            if ($hasVirtual) {
                $session = Mage::getSingleton('checkout/session')->setIsInvoiceAutoCapture(true);
                $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice($savedQtys);
                $invoice->register();
                $invoice->capture();

                $session->getIsInvoiceAutoCapture(true);

                $this->_onAfterAutoCapture($payment, $authTransaction);

                $order->addRelatedObject($invoice);
            }
        }
    }

    /**
     * Safely closes an unsaved AUTH transaction after an auto-capture.
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param Mage_Sales_Model_Order_Payment_Transaction $authTransaction
     */
    protected function _onAfterAutoCapture($payment, $authTransaction)
    {
        if ($payment->getShouldCloseParentTransaction() && !$authTransaction->getIsClosed()) {
            $authTransaction->isFailsafe(true)->close(false);
        }
    }
}
