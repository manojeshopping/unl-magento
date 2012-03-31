<?php

class Unl_Core_Model_Resource_Report_Reconcile_Cc_Products_Paid
    extends Unl_Core_Model_Resource_Report_Reconcile_Collection_Products_Paid
{
    public function __construct()
    {
        parent::__construct();
        $this->_paymentMethodCodes = Mage::helper('unl_core/report_bursar')->getPaymentMethodCodes('cc');
    }
}
