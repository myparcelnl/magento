<?php
/**
 * The class to provide functions for new_shipment.phtml
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

use Magento\Sales\Block\Adminhtml\Items\AbstractItems;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use Magento\Framework\App\ObjectManager;

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
     * @var \MyParcelNL\Magento\Model\Source\DefaultOptions
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
        \Magento\Backend\Block\Template\Context $context,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\ObjectManagerInterface $objectManager
    ) {
        // Set order
        $this->order = $registry->registry('current_shipment')->getOrder();
        $this->objectManager = $objectManager;

        $this->defaultOptions = new DefaultOptions(
            $this->order,
            $this->objectManager->get('\MyParcelNL\Magento\Helper\Data')
        );

        parent::__construct($context, $stockRegistry, $stockConfiguration, $registry);
    }

    /**
     * @param $option 'only_recipient'|'signature'|'return'|'large_format'
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
     * @throws \Magento\Framework\Exception\LocalizedException
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
        return json_decode($this->order->getData('delivery_options'), true);
    }
}
