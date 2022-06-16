<?php
/**
 * The class to provide functions for new_shipment.phtml
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

namespace MyParcelNL\Magento\Block\Sales;

use Magento\Backend\Block\Template\Context;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Sales\Block\Adminhtml\Items\AbstractItems;
use MyParcelNL\Magento\Helper\Checkout;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\TrackTraceHolder;

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
     * @var \MyParcelNL\Magento\Block\Sales\NewShipmentForm
     */
    private $form;

    /**
     * @var \MyParcelNL\Magento\Model\Sales\MagentoOrderCollection
     */
    private MagentoOrderCollection $orderCollection;

    /**
     * @var mixed
     */
    private $request;

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
        $this->order         = $registry->registry('current_shipment')->getOrder();
        $this->objectManager = $objectManager;
        $this->form          = new NewShipmentForm();

        $this->defaultOptions = new DefaultOptions(
            $this->order,
            $this->objectManager->get(Data::class)
        );

        $this->request         = $this->objectManager->get('Magento\Framework\App\RequestInterface');
        $this->orderCollection = $orderCollection ?? new MagentoOrderCollection($this->objectManager, $this->request);

        parent::__construct($context, $stockRegistry, $stockConfiguration, $registry);
    }

    /**
     * @param  string $option 'signature', 'only_recipient'
     * @param  string $carrier
     *
     * @return bool
     */
    public function hasDefaultOption(string $option, string $carrier): bool
    {
        return $this->defaultOptions->hasDefault($option, $carrier);
    }

    /**
     * @param  string $option 'large_format'
     * @param  string $carrier
     *
     * @return bool
     */
    public function hasDefaultLargeFormat(string $option, string $carrier): bool
    {
        return $this->defaultOptions->hasDefaultLargeFormat($carrier, $option);
    }

    /**
     * Get default value of age check
     *
     * @param  string $carrier
     * @param  string $option
     *
     * @return bool
     */
    public function hasDefaultOptionsWithoutPrice(string $carrier, string $option): bool
    {
        return $this->defaultOptions->hasDefaultOptionsWithoutPrice($carrier, $option);
    }

    /**
     * Get default value of insurance based on order grand total
     *
     * @param  string $carrier
     *
     * @return int
     */
    public function getDefaultInsurance(string $carrier): int
    {
        return $this->defaultOptions->getDefaultInsurance($carrier);
    }

    /**
     * Get default value of insurance based on order grand total
     * @return int
     */
    public function getDigitalStampWeight(): int
    {
        return $this->defaultOptions->getDigitalStampDefaultWeight();
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

    /**
     * @return \MyParcelNL\Magento\Block\Sales\NewShipmentForm
     */
    public function getNewShipmentForm(): NewShipmentForm
    {
        return $this->form;
    }

    /**
     * @return bool
     */
    public function isOrderManagementEnabled(): bool
    {
        return TrackTraceHolder::EXPORT_MODE_PPS == $this->orderCollection->getExportMode();
    }
}
