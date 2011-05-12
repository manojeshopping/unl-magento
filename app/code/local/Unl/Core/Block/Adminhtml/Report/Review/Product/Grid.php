<?php

class Unl_Core_Block_Adminhtml_Report_Review_Product_Grid extends Mage_Adminhtml_Block_Report_Review_Product_Grid
{
    /* Overrides
     * @see Mage_Adminhtml_Block_Report_Review_Product_Grid::_prepareCollection()
     * by adding scope filter
     */
    protected function _prepareCollection()
    {
        $collection = Mage::getResourceModel('reports/review_product_collection')
            ->joinReview();

        if ($scope = Mage::helper('unl_core')->getAdminUserScope()) {
            $collection->addAttributeToFilter('source_store_view', array('in' => $scope));
        }

        $this->setCollection($collection);

        return Mage_Adminhtml_Block_Widget_Grid::_prepareCollection();
    }
}
