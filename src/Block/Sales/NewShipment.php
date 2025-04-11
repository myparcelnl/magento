<?php

declare(strict_types=1);

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

use Exception;
use Magento\Backend\Block\Template\Context;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Sales\Block\Adminhtml\Items\AbstractItems;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Carrier\CarrierUPS;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

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
     * @param Context                     $context
     * @param StockRegistryInterface      $stockRegistry
     * @param StockConfigurationInterface $stockConfiguration
     * @param Registry                    $registry
     * @param ObjectManagerInterface      $objectManager
     */
    public function __construct(
        Context                     $context,
        StockRegistryInterface      $stockRegistry,
        StockConfigurationInterface $stockConfiguration,
        Registry                    $registry,
        ObjectManagerInterface      $objectManager
    )
    {
        $this->order         = $registry->registry('current_shipment')->getOrder();
        $this->weightService = $objectManager->get(Weight::class);
        $this->configService = $objectManager->get(Config::class);
        $this->form          = new NewShipmentForm();

        $this->defaultOptions = new DefaultOptions($this->order);

        parent::__construct($context, $stockRegistry, $stockConfiguration, $registry);
    }

    /**
     * @param string $option 'signature', 'only_recipient'
     * @param string $carrier
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
     * @param string $carrier
     *
     * @return int
     * @throws Exception
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
        $weight = $this->weightService->convertToGrams((float) $this->order->getWeight());

        if (0 === $weight) {
            $weight = $this->defaultOptions->getDigitalStampDefaultWeight();
        }

        return $weight;
    }

    /**
     * Get package type
     */
    public function getPackageType(): int
    {
        return $this->defaultOptions->getPackageType();
    }

    /**
     * @return string
     */
    public function getCarrier(): string
    {
        return $this->defaultOptions->getCarrierName();
    }

    /**
     * @return string
     */
    public function getCountry(): string
    {
        if (($address = $this->order->getShippingAddress())) {
            return $address->getCountryId();
        }

        return '';
    }

    public function getDeliveryType(): int
    {
        try {
            $deliveryTypeName = json_decode($this->order->getData(Config::FIELD_DELIVERY_OPTIONS), true)['deliveryType'];
            $deliveryType     = AbstractConsignment::DELIVERY_TYPES_NAMES_IDS_MAP[$deliveryTypeName];
        } catch (Throwable $e) {
            $deliveryType = AbstractConsignment::DEFAULT_DELIVERY_TYPE;
        }

        return $deliveryType;
    }

    public function consignmentHasShipmentOption(AbstractConsignment $consignment, string $shipmentOption): bool
    {
        /**
         * Business logic determining what shipment options to show, if any.
         */
        if (AbstractConsignment::SHIPMENT_OPTION_RECEIPT_CODE === $shipmentOption
            && AbstractConsignment::DELIVERY_TYPE_STANDARD !== $this->getDeliveryType()
        ) {
            return false; // receipt code is only available for standard delivery
        }

        if (AbstractConsignment::CC_NL === $consignment->getCountry()) {
            return $consignment->canHaveShipmentOption($shipmentOption);
        }

        // For PostNL in Belgium - recipient-only, signature and receipt-code are available
        if (AbstractConsignment::CC_BE === $consignment->getCountry() && CarrierPostNL::NAME === $consignment->getCarrierName()) {
            return in_array($shipmentOption, [
                AbstractConsignment::SHIPMENT_OPTION_ONLY_RECIPIENT,
                AbstractConsignment::SHIPMENT_OPTION_SIGNATURE,
                AbstractConsignment::SHIPMENT_OPTION_RECEIPT_CODE,
            ],              true);
        }

        // For UPS shipment options are available for all countries in the EU
        if (CarrierUPS::NAME === $consignment->getCarrierName()) {
            return true;
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
        return Config::EXPORT_MODE_PPS === $this->configService->getExportMode();
    }
}
