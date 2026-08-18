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
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Carrier\CarrierUPSStandard;
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
     * Unresolved on purpose: an unrecognised value matches no radio, so nothing is pre-selected
     * rather than the form suggesting a package type the customer never chose.
     */
    public function getPackageTypeName(): string
    {
        return $this->defaultOptions->getPackageTypeName() ?? PackageType::DEFAULT_NAME;
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

    /**
     * Null means the order carries a delivery type we do not recognise, so callers withhold
     * anything that depends on it rather than guessing. Absent is different: no stored type means
     * there was never a choice to honour, so it defaults quietly.
     */
    public function getDeliveryType(): ?int
    {
        try {
            $deliveryOptions  = json_decode($this->order->getData(Config::FIELD_DELIVERY_OPTIONS), true);
            $deliveryTypeName = $deliveryOptions['deliveryType'] ?? null;
        } catch (\Throwable $e) {
            $deliveryTypeName = null;
        }

        if (null === $deliveryTypeName) {
            return DeliveryType::DEFAULT;
        }

        $deliveryType = DeliveryType::toIdOrNull($deliveryTypeName);

        if (null === $deliveryType) {
            Logger::warning(sprintf(
                'Unrecognised delivery type "%s" on order %s; shipment options that depend on the '
                . 'delivery type are withheld rather than guessed.',
                (string) $deliveryTypeName,
                (string) $this->order->getIncrementId()
            ));
        }

        return $deliveryType;
    }

    public function consignmentHasShipmentOption(AbstractConsignment $consignment, string $shipmentOption): bool
    {
        // Receipt code is standard-only, so an unresolved delivery type fails closed.
        if (ShipmentOption::RECEIPT_CODE === $shipmentOption
            && DeliveryType::STANDARD !== $this->getDeliveryType()
        ) {
            return false; // receipt code is only available for standard delivery
        }

        if (CountryCode::CC_NL === $consignment->getCountry()) {
            return $consignment->canHaveShipmentOption($shipmentOption);
        }

        // For PostNL in Belgium - recipient-only, signature and receipt-code are available
        if (CountryCode::CC_BE === $consignment->getCountry() && CarrierPostNL::NAME === $consignment->getCarrierName()) {
            return in_array($shipmentOption, [
                ShipmentOption::ONLY_RECIPIENT,
                ShipmentOption::SIGNATURE,
                ShipmentOption::RECEIPT_CODE,
            ],true);
        }

        // For UPS shipment options are available for all countries in the EU
        if (CarrierUPSStandard::NAME === $consignment->getCarrierName()) {
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
