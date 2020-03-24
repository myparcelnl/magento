<?php
/**
 * The class to provide functions for new_shipment.phtml
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Block\Sales;

use Magento\Backend\Block\Template\Context;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Sales\Block\Adminhtml\Items\AbstractItems;
use MyParcelBE\Magento\Helper\Checkout;
use MyParcelBE\Magento\Model\Source\DefaultOptions;

class NewShipment extends AbstractItems
{
    /**
     * @var \Magento\Sales\Model\Order
     */
    private $order;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \MyParcelBE\Magento\Model\Source\DefaultOptions
     */
    private $defaultOptions;

    /**
     * @param \Magento\Backend\Block\Template\Context                   $context
     * @param \Magento\CatalogInventory\Api\StockRegistryInterface      $stockRegistry
     * @param \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration
     * @param \Magento\Framework\Registry                               $registry
     * @param \Magento\Framework\ObjectManagerInterface                 $objectManager
     */
    public function __construct(
        Context $context,
        StockRegistryInterface $stockRegistry,
        StockConfigurationInterface $stockConfiguration,
        Registry $registry,
        ObjectManagerInterface $objectManager
    ) {
        // Set order
        $this->order = $registry->registry('current_shipment')->getOrder();
        $this->objectManager = $objectManager;

        $this->defaultOptions = new DefaultOptions(
            $this->order,
            $this->objectManager->get('\MyParcelBE\Magento\Helper\Data')
        );

        parent::__construct($context, $stockRegistry, $stockConfiguration, $registry);
    }

    /**
     * @param $option 'signature'
     *
     * @return bool
     */
    public function getDefaultOption($option)
    {
        return $this->defaultOptions->getDefault($option);
    }

    /**
     * Get default value of insurance based on order grand total
     * @return int
     */
    public function getDefaultInsurance()
    {
        return $this->defaultOptions->getDefaultInsurance();
    }

    /**
     * Get package type
     */
    public function getPackageType()
    {
        return $this->defaultOptions->getPackageType();
    }

    /**
     * @return string
     */
    public function getCountry()
    {
        return $this->order->getShippingAddress()->getCountryId();
    }

    /**
     * Get all chosen options
     *
     * @return array
     */
    public function getChosenOptions()
    {
        return json_decode($this->order->getData(Checkout::FIELD_DELIVERY_OPTIONS), true);
    }
}
