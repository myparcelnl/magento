<?php
/**
 * Show MyParcel options in config support tab
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelBE\Magento\Block\System\Config\Form;

use MyParcelBE\Magento\Helper\Data;

class SupportTabRepository extends \Magento\Sales\Block\Adminhtml\Order\AbstractOrder
{/**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    protected $moduleList;
    /**
     * @var \MyParcelBE\Magento\Helper\Data
     */
    private $helper;

    /**
     * MyParcelSupportTab constructor
     *
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Framework\Registry             $registry
     * @param \Magento\Sales\Helper\Admin             $adminHelper
     * @param \MyParcelBE\Magento\Helper\Data         $helper
     * @param array                                   $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Sales\Helper\Admin $adminHelper,
        \MyParcelBE\Magento\Helper\Data $helper,
        array $data = []
    ) {
        parent::__construct($context, $registry, $adminHelper, $data);
        $this->helper = $helper;
    }

    /**
     * Get the url of the stylesheet
     *
     * @return string
     */
    public function getCssUrl()
    {
        $cssUrl = $this->_assetRepo->createAsset('MyParcelBE_Magento::css/config/support_tab/style.css')->getUrl();

        return $cssUrl;
    }

    /**
     * Get the version number of the installed module
     *
     * @return string
     */
    public function getModuleVersion()
    {
        return $this->helper->getVersion();
    }
}
