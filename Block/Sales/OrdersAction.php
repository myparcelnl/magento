<?php
/**
 * Block for order actions (multiple orders action and one order action)
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Block\Sales;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use MyParcelBE\Magento\Helper\Data;

class OrdersAction extends Template
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \MyParcelBE\Magento\Helper\Data
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
        $this->helper        = $this->objectManager->get(Data::class);
        parent::__construct($context, $data);
    }

    /**
     * Check if global API Key isset
     *
     * @return bool
     */
    public function hasApiKey(): bool
    {
        return $this->helper->hasApiKey();
    }

    /**
     * Get url to create and print MyParcel track
     *
     * @return string
     */
    public function getOrderAjaxUrl(): string
    {
        return $this->_urlBuilder->getUrl('myparcel/order/CreateAndPrintMyParcelTrack');
    }

    /**
     * Get url to create and print MyParcel track
     *
     * @return string
     */
    public function getShipmentAjaxUrl(): string
    {
        return $this->_urlBuilder->getUrl('myparcel/shipment/CreateAndPrintMyParcelTrack');
    }

    /**
     * Get url to send a mail with a return label
     *
     * @return string
     */
    public function getAjaxUrlSendReturnMail(): string
    {
        return $this->_urlBuilder->getUrl('myparcel/order/SendMyParcelReturnMail');
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
