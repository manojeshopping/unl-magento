<?php

class Unl_Core_Block_Adminhtml_Sales_Order_Grid extends Mage_Adminhtml_Block_Sales_Order_Grid
{
    protected function _prepareCollection()
    {
        /* @var $collection Mage_Sales_Model_Mysql4_Order_Collection */
        $collection = Mage::getResourceModel('sales/order_collection')
            ->addAttributeToSelect('*')
            ->joinAttribute('billing_firstname', 'order_address/firstname', 'billing_address_id', null, 'left')
            ->joinAttribute('billing_lastname', 'order_address/lastname', 'billing_address_id', null, 'left')
            ->joinAttribute('shipping_firstname', 'order_address/firstname', 'shipping_address_id', null, 'left')
            ->joinAttribute('shipping_lastname', 'order_address/lastname', 'shipping_address_id', null, 'left')
            ->addExpressionAttributeToSelect('billing_name',
                'CONCAT({{billing_firstname}}, " ", {{billing_lastname}})',
                array('billing_firstname', 'billing_lastname'))
            ->addExpressionAttributeToSelect('shipping_name',
                'CONCAT({{shipping_firstname}},  IFNULL(CONCAT(\' \', {{shipping_lastname}}), \'\'))',
                array('shipping_firstname', 'shipping_lastname'));
        
        $user  = Mage::getSingleton('admin/session')->getUser();
        if (!is_null($user->getScope())) {
            $scope = explode(',', $user->getScope());
            $order_items = Mage::getModel('sales/order_item')->getCollection();
            /* @var $order_items Mage_Sales_Model_Mysql4_Order_Item_Collection */
            $select = $order_items->getSelect()->reset(Zend_Db_Select::COLUMNS)
                ->columns(array('order_id'))
                ->where('source_store_view IN (?)', $scope)
                ->group('order_id');
                
            $collection->getSelect()
                ->joinInner(array('scope' => $select), 'e.entity_id = scope.order_id', array());
        }
        
        $this->setCollection($collection);
        
        return Mage_Adminhtml_Block_Widget_Grid::_prepareCollection();
    }
    
    protected function _prepareColumns()
    {
        $this->addColumn('real_order_id', array(
            'header'=> Mage::helper('sales')->__('Order #'),
            'width' => '80px',
            'type'  => 'text',
            'index' => 'increment_id',
        ));
        
        $this->addColumn('external_id', array(
            'header' => Mage::helper('sales')->__('External #'),
            'width'  => '80px',
            'type'   => 'text',
            'index'  => 'external_id'
        ));

        /*if (!Mage::app()->isSingleStoreMode()) {
            $this->addColumn('store_id', array(
                'header'    => Mage::helper('sales')->__('Purchased from (store)'),
                'index'     => 'store_id',
                'type'      => 'store',
                'store_view'=> true,
                'display_deleted' => true,
            ));
        }*/

        $this->addColumn('created_at', array(
            'header' => Mage::helper('sales')->__('Purchased On'),
            'index' => 'created_at',
            'type' => 'datetime',
            'width' => '100px',
        ));

        /*$this->addColumn('billing_firstname', array(
            'header' => Mage::helper('sales')->__('Bill to First name'),
            'index' => 'billing_firstname',
        ));

        $this->addColumn('billing_lastname', array(
            'header' => Mage::helper('sales')->__('Bill to Last name'),
            'index' => 'billing_lastname',
        ));*/
        $this->addColumn('billing_name', array(
            'header' => Mage::helper('sales')->__('Bill to Name'),
            'index' => 'billing_name',
        ));

        /*$this->addColumn('shipping_firstname', array(
            'header' => Mage::helper('sales')->__('Ship to First name'),
            'index' => 'shipping_firstname',
        ));

        $this->addColumn('shipping_lastname', array(
            'header' => Mage::helper('sales')->__('Ship to Last name'),
            'index' => 'shipping_lastname',
        ));*/
        $this->addColumn('shipping_name', array(
            'header' => Mage::helper('sales')->__('Ship to Name'),
            'index' => 'shipping_name',
        ));

        $this->addColumn('base_grand_total', array(
            'header' => Mage::helper('sales')->__('G.T. (Base)'),
            'index' => 'base_grand_total',
            'type'  => 'currency',
            'currency' => 'base_currency_code',
        ));

        $this->addColumn('grand_total', array(
            'header' => Mage::helper('sales')->__('G.T. (Purchased)'),
            'index' => 'grand_total',
            'type'  => 'currency',
            'currency' => 'order_currency_code',
        ));

        $this->addColumn('status', array(
            'header' => Mage::helper('sales')->__('Status'),
            'index' => 'status',
            'type'  => 'options',
            'width' => '70px',
            'options' => Mage::getSingleton('sales/order_config')->getStatuses(),
        ));

        if (Mage::getSingleton('admin/session')->isAllowed('sales/order/actions/view')) {
            $this->addColumn('action',
                array(
                    'header'    => Mage::helper('sales')->__('Action'),
                    'width'     => '50px',
                    'type'      => 'action',
                    'getter'     => 'getId',
                    'actions'   => array(
                        array(
                            'caption' => Mage::helper('sales')->__('View'),
                            'url'     => array('base'=>'*/*/view'),
                            'field'   => 'order_id'
                        )
                    ),
                    'filter'    => false,
                    'sortable'  => false,
                    'index'     => 'stores',
                    'is_system' => true,
            ));
        }
        $this->addRssList('rss/order/new', Mage::helper('sales')->__('New Order RSS'));

        return Mage_Adminhtml_Block_Widget_Grid::_prepareColumns();
    }
}