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
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Sales\Block\Adminhtml\Items\AbstractItems;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Model\Sales\TrackTraceHolder;
use MyParcelNL\Magento\Service\Config\ConfigService;
use MyParcelNL\Magento\Service\Weight\WeightService;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class NewShipment extends AbstractItems
{
    /**
     * @var \Magento\Sales\Model\Order
     */
    private $order;

    /**
     * @var \MyParcelNL\Magento\Model\Source\DefaultOptions
     */
    private DefaultOptions $defaultOptions;

    /**
     * @var \MyParcelNL\Magento\Block\Sales\NewShipmentForm
     */
    private NewShipmentForm $form;

    /**
     * @var \MyParcelNL\Magento\Model\Sales\MagentoOrderCollection
     */
    private MagentoOrderCollection $orderCollection;

    /**
     * @param Context $context
     * @param StockRegistryInterface $stockRegistry
     * @param StockConfigurationInterface $stockConfiguration
     * @param Registry $registry
     */
    public function __construct(
        Context $context,
        StockRegistryInterface $stockRegistry,
        StockConfigurationInterface $stockConfiguration,
        Registry $registry,
        ObjectManagerInterface $objectManager
    ) {
        $this->order         = $registry->registry('current_shipment')->getOrder();
        $this->weightService = $objectManager->get(WeightService::class);
        $this->configService = $objectManager->get(ConfigService::class);
        $this->form          = new NewShipmentForm();

        $this->defaultOptions = new DefaultOptions($this->order);

        $request         = $objectManager->get('Magento\Framework\App\RequestInterface');
        $this->orderCollection = new MagentoOrderCollection($objectManager, $request);

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
        return $this->defaultOptions->hasOptionSet($option, $carrier);
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
        $weight = $this->weightService->convertToGrams($this->order->getWeight() ?? 0.0);

        if (0 === $weight) {
            $weight = $this->defaultOptions->getDigitalStampDefaultWeight();
        }

        return $weight;
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
    public function getCarrier(): string
    {
        return $this->defaultOptions->getCarrier();
    }

    /**
     * @return string
     */
    public function getCountry()
    {
        return $this->order->getShippingAddress()->getCountryId();
    }

    public function consignmentHasShipmentOption(AbstractConsignment $consignment, string $shipmentOption): bool
    {
        /**
         * Business logic determining what shipment options to show, if any.
         */
        if (AbstractConsignment::CC_NL === $consignment->getCountry()) {
            return $consignment->canHaveShipmentOption($shipmentOption);
        }

        // For PostNL in Belgium - only recipient-only/signature is available
        if (AbstractConsignment::CC_BE === $consignment->getCountry() && CarrierPostNL::NAME === $consignment->getCarrierName()) {
            return in_array($shipmentOption, [
                AbstractConsignment::SHIPMENT_OPTION_ONLY_RECIPIENT,
                AbstractConsignment::SHIPMENT_OPTION_SIGNATURE], true);
        }

        // No shipment options available in any other cases
        return false;
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
        return ConfigService::EXPORT_MODE_PPS === $this->configService->getExportMode();
    }
}
