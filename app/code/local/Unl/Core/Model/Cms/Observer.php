<?php

class Unl_Core_Model_Cms_Observer
{

    /**
     * A keyed array of string arrays. The keys are the theme identifiers
     * and the values are arrays of WDN CSS URLs to load
     *
     * @var array
     */
    protected $_themeUnlCss = array(
        'default' => array(  // 3.1 WDN Templates
            '/wdn/templates_3.1/css/compressed/base.css',
            '/wdn/templates_3.1/css/variations/media_queries.css',
        ),
        'four' => array(  // 4.0 WDN Templates
            '//cloud.typography.com/7717652/616662/css/fonts.css',
            '/wdn/templates_4.0/css/all.css',
        )
    );

    /**
     * An <i>adminhtml</i> event observer for the <code>cms_wysiwyg_config_prepare</code>
     * event. It extends the default CMS config.
     *
     * @param Varien_Event_Observer $observer
     */
    public function prepareWysiwygConfig($observer)
    {
        $config = $observer->getEvent()->getConfig();

        // Add CSS from the Design
        if (!$config->getDisableDesignCss()) {
            $css = $this->_getWysiwygCss();

            if ($config->getContentCss()) {
                array_unshift($css, $config->getContentCss());
            }

            $config->setContentCss(implode(',', $css));
        }

        $config->setBodyId('maincontent');
        $config->setBodyClass('fixed');
        $config->setExtendedValidElements('iframe[align|frameborder|height|longdesc|marginheight|marginwidth|name|scrolling|src|width|class|id|style|title]');

        // Fix bad default values
        $config->setData('directives_url', Mage::getSingleton('adminhtml/url')->getUrl('adminhtml/cms_wysiwyg/directive'));
        $config->setData('directives_url_quoted', preg_quote($config->getData('directives_url')));
        $config->setData('files_browser_window_url', Mage::getSingleton('adminhtml/url')->getUrl('adminhtml/cms_wysiwyg_images/index'));
    }

    /**
     * Returns an array of CSS files to load for the WYSIWYG tool
     *
     * @return string
     */
    protected function _getWysiwygCss()
    {
        $design = Mage::getModel('core/design_package')->setStore(Mage::app()->getDefaultStoreView());

        if ($design->getPackageName() == 'unl') {
            $css = isset($this->_themeUnlCss[$design->getFallbackTheme()]) ? $this->_themeUnlCss[$design->getFallbackTheme()] : array();
        } else {
            $css = array();
        }

         $css[] = $design->getSkinUrl('css/styles.css');

         return $css;
    }
}
