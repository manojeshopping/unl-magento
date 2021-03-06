<?php

class Unl_Email_Model_Core_Email_Template extends Mage_Core_Model_Email_Template
{
    /* Overrides
     * @see Mage_Core_Model_Email_Template::sendTransactional()
     * by applying the design config while loading defaults
     * and dispatching an event to add additional variables
     */
    public function sendTransactional($templateId, $sender, $email, $name, $vars=array(), $storeId=null)
    {
        $this->setSentSuccess(false);
        if (($storeId === null) && $this->getDesignConfig()->getStore()) {
            $storeId = $this->getDesignConfig()->getStore();
        }

        if (is_numeric($templateId)) {
            $this->load($templateId);
        } else {
            $localeCode = Mage::getStoreConfig('general/locale/code', $storeId);
            // emulate the design to get design locale files
            $this->_applyDesignConfig();
            $this->loadDefault($templateId);
            $this->_cancelDesignConfig();
        }

        if (!$this->getId()) {
            throw Mage::exception('Mage_Core', Mage::helper('core')->__('Invalid transactional email code: '.$templateId));
        }

        if (!is_array($sender)) {
            $this->setSenderName(Mage::getStoreConfig('trans_email/ident_'.$sender.'/name', $storeId));
            $this->setSenderEmail(Mage::getStoreConfig('trans_email/ident_'.$sender.'/email', $storeId));
        } else {
            $this->setSenderName($sender['name']);
            $this->setSenderEmail($sender['email']);
        }

        if (!isset($vars['store'])) {
            $vars['store'] = Mage::app()->getStore($storeId);
        }

        $transport = new Varien_Object($vars);
        Mage::dispatchEvent('core_email_template_prepare_vars', array(
            'template' => $this,
            'transport' => $transport
        ));
        $vars = $transport->getData();

        $this->setSentSuccess($this->send($email, $name, $vars));
        return $this;
    }
}
