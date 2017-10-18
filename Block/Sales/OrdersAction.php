<?php
/**
 * Block for order actions (multiple orders action and one order action)
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Block\Sales;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;

class OrdersAction extends Template
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \MyParcelNL\Magento\Helper\Data
     */
    private $helper;

    /**
     * @param Context $context
     * @param array   $data
     */
    public function __construct(
        Context $context,
        array $data = []
    ) {
        $this->objectManager = ObjectManager::getInstance();
        $this->helper = $this->objectManager->get('\MyParcelNL\Magento\Helper\Data');
        parent::__construct($context, $data);
    }

    /**
     * Check if global API Key isset
     *
     * @return bool
     */
    public function hasApiKey()
    {
        $apiKey = $this->helper->getGeneralConfig('api/key');

        return $apiKey == '' ? 'false' : 'true';
    }

    /**
     * Get url to create and print MyParcel track
     *
     * @return string
     */
    public function getOrderAjaxUrl()
    {
        return $this->_urlBuilder->getUrl('myparcelnl/order/CreateAndPrintMyParcelTrack');
    }

    /**
     * Get url to create and print MyParcel track
     *
     * @return string
     */
    public function getShipmentAjaxUrl()
    {
        return $this->_urlBuilder->getUrl('myparcelnl/shipment/CreateAndPrintMyParcelTrack');
    }

    /**
     * Get url to send a mail with a return label
     *
     * @return string
     */
    public function getAjaxUrlSendReturnMail()
    {
        return $this->_urlBuilder->getUrl('myparcelnl/order/SendMyParcelReturnMail');
    }

    /**
     * Get print settings
     *
     * @return string
     */
    public function getPrintSettings()
    {
        $settings = $this->helper->getGeneralConfig('print');

        return json_encode($settings);
    }
}
