<?php

class Unl_Spam_Model_Observer
{
    const XML_PATH_SFS_ACTIVE      = 'unl_spam/sfs/active';
    const XML_PATH_SFS_API_KEY     = 'unl_spam/sfs/api_key';
    const XML_PATH_QUARANTINE_TIME = 'unl_spam/blacklist/quarantine_time';
    const XML_PATH_QUARANTINE_HITS = 'unl_spam/blacklist/quarantine_strikes';
    const XML_PATH_BLACKLIST_HITS  = 'unl_spam/blacklist/blacklist_strikes';

    public function onContactPostPredispatch($observer)
    {
        if (!Mage::getStoreConfigFlag(Mage_Contacts_IndexController::XML_PATH_ENABLED)) {
            return;
        }

        $controller = $observer->getEvent()->getControllerAction();
        $doQuarantine = $this->_checkSfs();

        $post = $controller->getRequest()->getPost();
        // Check for spam to report
        if ($post) {
            if (!isset($post['hideit']) || preg_match('#(?:url|href)=["\']https?://[^"\']+["\']#', $post['comment'])) {
                $doQuarantine = true;
                $this->_reportToSfs($post);
            }
        }

        if ($doQuarantine) {
            $this->_quarantineIp();

            $ip = Mage::helper('core/http')->getRemoteAddr();
            Mage::log('IP Address "' . $ip . '" detected spam, added to quarantine.', Zend_Log::INFO, 'unl_spam.log');

            $ex = new Mage_Core_Controller_Varien_Exception();
            $ex->prepareForward('denied', 'index', 'unlspam');

            throw $ex;
        }
    }

    protected function _checkSfs()
    {
        if (Mage::getStoreConfigFlag(self::XML_PATH_SFS_ACTIVE)) {
            $remoteAddr = Mage::helper('unl_spam')->getRemoteAddr();
            $sfsCache = Mage::getModel('unl_spam/sfs_cache')->load($remoteAddr, 'remote_addr');

            if (!$sfsCache->getId()) {
                $sfsCache->setRemoteAddr(Mage::helper('core/http')->getRemoteAddr());
            }

            if (!$sfsCache->isValid()) {
                $sfsCache->fetch();
            }

            return $sfsCache->isSpam();
        }

        return false;
    }

    protected function _reportToSfs($post)
    {
        if (Mage::getStoreConfigFlag(self::XML_PATH_SFS_ACTIVE) && ($apiKey = Mage::getStoreConfig(self::XML_PATH_SFS_API_KEY))) {
            $client = new Zend_Http_Client('http://www.stopforumspam.com/add', array(
                'maxredirects' => 0,
                'useragent' => 'Magento ver/' . Mage::getVersion(),
                'timeout' => 30,
            ));

            $data = array(
                'username' => isset($post['name']) ? $post['name'] : 'spammer',
                'email'    => isset($post['email']) ? $post['email'] : 'spammer@example.com',
                'ip'       => Mage::helper('core/http')->getRemoteAddr(),
                'api_key'  => $apiKey,
                'evidence' => $post['comment'],
            );

            $client->setParameterPost($data);

            $client->request(Zend_Http_Client::POST);

            Mage::log('Reported SPAM to SFS with: ' . print_r($data, true), Zend_Log::NOTICE, 'unl_spam.log');
        }
    }

    protected function _quarantineIp()
    {
        $remoteAddr = Mage::helper('unl_spam')->getRemoteAddr();

        /* @var $collection Unl_Spam_Model_Resource_Quarantine_Collection */
        $collection = Mage::getModel('unl_spam/quarantine')->getCollection();
        $collection->addFieldToFilter('remote_addr', $remoteAddr);
        $collection->addOrder('strikes', 'DESC');
        $collection->setPageSize(1);

        $ttl = Mage::getStoreConfig(self::XML_PATH_QUARANTINE_TIME) * 60;
        $strikes = 1;
        if (count($collection)) {
            $item = $collection->getFirstItem();
            $strikes += $item->getStrikes();
        }

        $quarantine = Mage::getModel('unl_spam/quarantine');
        $quarantine->setRemoteAddr(Mage::helper('core/http')->getRemoteAddr());
        $quarantine->setStrikes($strikes);
        $quarantine->setExpiresAt(Mage::getSingleton('core/date')->gmtDate(time() + $ttl));

        $quarantine->save();

        return $this;
    }

    public function onHoneypotPredispatch($observer)
    {
        $controller = $observer->getEvent()->getControllerAction();
        if ($controller instanceof Mage_Contacts_IndexController
            && !Mage::getStoreConfigFlag(Mage_Contacts_IndexController::XML_PATH_ENABLED)
        ) {
            return;
        }

        if ($this->_checkQuarantine()) {
            $ip = Mage::helper('core/http')->getRemoteAddr();
            Mage::log('IP Address "' . $ip . '" denied by quarantine.', Zend_Log::INFO, 'unl_spam.log');

            $ex = new Mage_Core_Controller_Varien_Exception();
            $ex->prepareForward('denied', 'index', 'unlspam');

            throw $ex;
        }
    }

    public function onAllPredispatch($observer)
    {
        $controller = $observer->getEvent()->getControllerAction();
        if ($controller instanceof Unl_Spam_IndexController) {
            return;
        }

        if ($blacklist = $this->_checkBlacklist()) {
            $ip = Mage::helper('core/http')->getRemoteAddr();
            Mage::log('IP Address "' . $ip . '" blocked by blacklist.', Zend_Log::INFO, 'unl_spam.log');

            $ex = new Mage_Core_Controller_Varien_Exception();
            switch ($blacklist->getResponseType()) {
                case Unl_Spam_Model_Blacklist::RESPONSE_TYPE_503:
                    $ex->prepareForward('unavailable', 'index', 'unlspam');
                    break;
                default:
                    $ex->prepareForward('denied', 'index', 'unlspam');
                    break;
            }

            throw $ex;
        }
    }

    protected function _checkQuarantine()
    {
        $remoteAddr = Mage::helper('unl_spam')->getRemoteAddr();

        /* @var $collection Unl_Spam_Model_Resource_Quarantine_Collection */
        $collection = Mage::getModel('unl_spam/quarantine')->getCollection();
        $collection->addFieldToFilter('remote_addr', $remoteAddr);
        $collection->addFieldToFilter('expires_at', array('gt' => Mage::getSingleton('core/date')->gmtDate()));

        if (!$collection->getSize()) {
            return false;
        }

        $item = $collection->getFirstItem();
        $ttl = Mage::getStoreConfig(self::XML_PATH_QUARANTINE_TIME) * 60;
        $item->setExpiresAt(Mage::getSingleton('core/date')->gmtDate(time() + ($ttl * pow(2, $item->getStrikes()))));
        $item->setStrikes($item->getStrikes() + 1);

        $transaction = Mage::getModel('core/resource_transaction')->addObject($item);

        if ($item->getStrikes() >= Mage::getStoreConfig(self::XML_PATH_QUARANTINE_HITS)) {
            $blacklist = Mage::getModel('unl_spam/blacklist');
            $blacklist->setRemoteAddr($item->getRemoteAddr());
            $blacklist->setResponseType(Unl_Spam_Model_Blacklist::RESPONSE_TYPE_403);
            $blacklist->setLastSeen(Mage::getSingleton('core/date')->gmtDate());

            Mage::log('IP Address "' . $item->getRemoteAddr() . '" automatically added to blacklist.', Zend_Log::INFO, 'unl_spam.log');

            $transaction->addObject($blacklist);
        }

        $transaction->save();

        return true;
    }

    /**
     * Searches for the remote IP in the spam blacklist
     *
     * @return boolean|Unl_Spam_Model_Blacklist
     */
    protected function _checkBlacklist()
    {
        $remoteAddr = Mage::helper('unl_spam')->getRemoteAddr();

        /* @var $collection Unl_Spam_Model_Resource_blacklist_Collection */
        $collection = Mage::getModel('unl_spam/blacklist')->getCollection();
        $collection->addFieldToFilter('remote_addr', $remoteAddr);
        $collection->addFieldToFilter('cidr_mask', array('null' => true));

        if (!$collection->getSize()) {
            $collection = Mage::getModel('unl_spam/blacklist')->getCollection();

            $collection->getSelect()->where($collection->getConnection()->quoteInto(
                '(? & ' . $collection->getConnection()->quoteIdentifier('cidr_mask') . ') = ' .
                    $collection->getConnection()->quoteIdentifier('remote_addr'),
                $remoteAddr
            ));
            $collection->addFieldToFilter('cidr_mask', array('notnull' => true));
        }

        if (!$collection->getSize()) {
            return false;
        }

        $item = $collection->getFirstItem();
        $item->setStrikes($item->getStrikes() + 1);
        $item->setLastSeen(Mage::getSingleton('core/date')->gmtDate());

        if ($item->getStrikes() >= Mage::getStoreConfig(self::XML_PATH_BLACKLIST_HITS)) {
            $item->setResponseType(Unl_Spam_Model_Blacklist::RESPONSE_TYPE_503);

            Mage::log('IP Address "' . $item->getRemoteAddr() . '" converted to 503 response.', Zend_Log::INFO, 'unl_spam.log');
        }

        $item->save();

        return $item;
    }
}
