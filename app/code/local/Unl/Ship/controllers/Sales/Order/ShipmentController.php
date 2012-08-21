<?php

require_once 'Mage/Adminhtml/controllers/Sales/Order/ShipmentController.php';

class Unl_Ship_Sales_Order_ShipmentController extends Mage_Adminhtml_Sales_Order_ShipmentController
{
    /* Extends
     * @see Mage_Adminhtml_Sales_Order_ShipmentController::printLabelAction()
     * by optionally forwarding the request to a different PDF generator
     */
    public function printLabelAction()
    {
        if (!Mage::getStoreConfigFlag('sales/shipment_label/use_unl')) {
            parent::printLabelAction();
            return;
        }

        $this->_forward('labelPdf', 'sales_order_package');
    }

    /* Extends
     * @see Mage_Adminhtml_Sales_Order_ShipmentController::massPrintShippingLabelAction()
     * by optionally forwarding the request to a different PDF generator
     */
    public function massPrintShippingLabelAction()
    {
        if (!Mage::getStoreConfigFlag('sales/shipment_label/use_unl')) {
            parent::massPrintShippingLabelAction();
            return;
        }

        $request = $this->getRequest();
        $createdFromOrders = true;
        $ids = array();
        /* @var $packages Unl_Ship_Model_Resource_Shipment_Package_Collection */
        switch ($request->getParam('massaction_prepare_key')) {
            case 'shipment_ids':
                $createdFromOrders = false;
                $ids = $request->getParam('shipment_ids');
                $filter = 'shipment_id';
                break;
            case 'order_ids':
                $ids = $request->getParam('order_ids');
                $filter = 'order_id';
                break;
        }

        array_filter($ids, 'intval');
        if (!empty($ids)) {
            $packages = Mage::getResourceModel('unl_ship/shipment_package_collection');
            $packages->selectNoData()
                ->addFieldToFilter($filter, array('in' => $ids));

            $ids = $packages->getAllIds();
        }

        if (!empty($ids)) {
            $this->_forward('labelPdf', 'sales_order_package', null, array('id' => implode('|', $ids)));
            return;
        } else {
            $createdFromPartErrorMsg = $createdFromOrders ? 'orders' : 'shipments';
            $this->_getSession()
                ->addError(Mage::helper('sales')->__('There are no shipping labels related to selected %s.', $createdFromPartErrorMsg));
        }

        if ($createdFromOrders) {
            $this->_redirect('*/sales_order/index');
        } else {
            $this->_redirect('*/sales_order_shipment/index');
        }
    }

    public function queueOrdersAction()
    {
        $orderIds = $this->getRequest()->getPost('order_ids');
        if (!empty($orderIds)) {
            $helper = Mage::helper('unl_ship');
            $queue = $helper->getUnlShipQueue();
            $count = 0;
            if (!$queue) {
                $queue = array();
            }

            $collection = Mage::getModel('sales/order')->getResourceCollection();
            $collection->addFieldToFilter('entity_id', array('in' => $orderIds));
            foreach ($collection as $order) {
                if ($order->canShip()) {
                    $count++;
                    $queue[] = $order->getId();
                }
            }
            if ($count) {
                $this->_getSession()->addSuccess($this->__('%s order(s) have been queued for auto ship.', $count));
                $orderId = array_shift($queue);
                $helper->setUnlShipQueue($queue);
                return $this->_redirect('*/*/new', array(
                    'order_id' => $orderId
                ));
            } else {
                $this->_getSession()->addError($this->__('There are no shippable orders in the selected orders.'));
                return $this->_redirect('*/sales_order/');
            }

        }

        $this->_redirect('*/sales_order/');
    }

    public function nextInQueueAction()
    {
        $helper = Mage::helper('unl_ship');
        if (!$helper->isUnlShipQueueEmpty()) {
            $orderId = $helper->dequeueUnlShipQueue();
            $this->_redirect('*/*/new', array(
                'order_id' => $orderId
            ));
        } else {
            $this->_getSession()->addError($this->__('The auto ship queue is empty.'));
            $this->_redirect('*/sales_order/');
        }
    }

    public function clearQueueAction()
    {
        Mage::helper('unl_ship')->getUnlShipQueue(true);
        $this->_getSession()->addSuccess($this->__('Successfully cleared the auto ship queue.'));
        $this->_redirect('adminhtml/sales_order/');
    }

    public function voidAction()
    {
        try {
            $shipment = $this->_initShipment();
            if (!$shipment) {
                $this->_forward('noRoute');
                return;
            }

            $carrier = $shipment->getOrder()->getShippingCarrier();

            $data = array();
            foreach ($shipment->getAllTracks() as $track) {
                if ($track->getCarrierCode() == $carrier->getCarrierCode()) {
                    $data[] = array('tracking_number' => $track->getTrackNumber());
                }
            }

            if ($data) {
                if (!$carrier || !$carrier instanceof Unl_Ship_Model_Shipping_Carrier_VoidInterface || !$carrier->isVoidAvailable()) {
                    Mage::throwException($this->__('The shipping carrier does not support voiding shipments.'));
                }

                if (!$carrier->requestToVoid($data)) {
                    Mage::throwException($this->__('Failed to void a tracking number from the shipping carrier'));
                }
            }

            $shipment->unregister();

            $shipment->getOrder()->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $this->__('Voided and deleted a shipment for reprocessing.'));
            $this->_saveShipment($shipment);
            $this->_getSession()->addSuccess($this->__('Successfully voided and deleted shipment.'));
        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            $this->_redirect('*/*/view', array('shipment_id' => $this->getRequest()->getParam('shipment_id')));
            return;
        } catch (Exception $e) {
            $this->_getSession()->addError($this->__('Cannot void shipment.'));
            $this->_redirect('*/*/view', array('shipment_id' => $this->getRequest()->getParam('shipment_id')));
            return;
        }

        $this->_redirect('*/sales_order/view', array('order_id' => $shipment->getOrderId()));
    }
}
