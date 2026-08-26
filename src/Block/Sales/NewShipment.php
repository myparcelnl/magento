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
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

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

    private CapabilitiesRepository $capabilitiesRepository;

    /**
     * Keyed by package type, with '' for the package-type-agnostic lookup. One entry per distinct
     * question this render asks, so repeated asks for the same package type cost nothing.
     *
     * @var array<string,CapabilitySet>
     */
    private array $capabilities = [];

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

        $this->capabilitiesRepository = $objectManager->get(CapabilitiesRepository::class);

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

    /**
     * Capabilities for one shipment shape. Pass null for the shape-agnostic question — which
     * carriers, and which package types each has.
     *
     * **A package-type-agnostic response is a superset, not a matrix.** The endpoint answers for the
     * shipment shape it is given, and `packageType` is singular. Ask without one and the API groups
     * every package type of a carrier into a single result carrying the union of their options — so
     * a mailbox would inherit the options of a package. Options, insurance and the collo maximum
     * therefore have to be asked per package type; only the carrier and package-type lists come
     * from the broad call.
     *
     * An order with no shipping address has no country to ask about, so it gets the permissive set
     * rather than a country we invented for it.
     */
    private function getCapabilities(?string $packageType = null): CapabilitySet
    {
        $key = (string) $packageType;

        if (isset($this->capabilities[$key])) {
            return $this->capabilities[$key];
        }

        $country = $this->getCountry();

        if ('' === $country) {
            return $this->capabilities[$key] = CapabilitySet::permissive();
        }

        $request = CapabilitiesRequest::forCountry($country);

        if (null !== $packageType) {
            $v2 = PackageType::toV2Name($packageType);

            if (null === $v2) {
                // Nothing to ask about: an unmappable package type cannot be sent, and the broad
                // answer would over-report. Withhold rather than guess.
                return $this->capabilities[$key] = CapabilitySet::permissive();
            }

            $request = $request->withPackageType($v2);
        }

        return $this->capabilities[$key] = $this->capabilitiesRepository->forStore(
            (int) $this->order->getStoreId(),
            $request
        );
    }

    /**
     * Carriers to offer: those the account has a contract for, narrowed to those this module has
     * settings for. A carrier the account has but we have no config path for cannot be offered —
     * it would have no fee, no active flag and no drop-off days. The Repository already logs any
     * carrier name the module does not know, and V2NameMapTest pins the two lists to the same keys,
     * so there is no second gap to report here.
     *
     * @return string[]
     */
    public function getCarriers(): array
    {
        $configured = array_keys(Config::CARRIERS_XML_PATH_MAP);

        if ($this->getCapabilities()->isPermissive()) {
            return $configured;
        }

        return array_values(array_intersect($configured, $this->getCapabilities()->carriers()));
    }

    /**
     * @return string[] module package type names
     */
    public function getPackageTypes(string $carrier): array
    {
        if ($this->getCapabilities()->isPermissive()) {
            // What this form offered before capabilities existed. Degrading to the old behaviour
            // beats both hiding everything and offering pallets that never appeared here.
            return array_map(
                [PackageType::class, 'nameFromId'],
                array_keys(NewShipmentForm::PACKAGE_TYPE_HUMAN_MAP)
            );
        }

        return $this->getCapabilities()->packageTypesFor($carrier);
    }

    /**
     * Options to render as checkboxes. Insurance is excluded: the template renders it as an amount
     * selector of its own.
     *
     * @return string[]
     */
    public function getShipmentOptions(string $carrier, string $packageType): array
    {
        $caps = $this->getCapabilities($packageType);

        $options = $caps->isPermissive()
            ? ShipmentOption::TO_CHECK
            : $caps->optionsFor($carrier, $packageType);

        return array_values(array_filter(
            $options,
            function (string $option) use ($carrier, $packageType): bool {
                return ShipmentOption::INSURANCE !== $option
                       && $this->hasShipmentOption($carrier, $packageType, $option);
            }
        ));
    }

    public function hasShipmentOption(string $carrier, string $packageType, string $shipmentOption): bool
    {
        // Receipt code is standard-only. Our own rule, not capability data, and an unresolved
        // delivery type fails closed.
        if (ShipmentOption::RECEIPT_CODE === $shipmentOption
            && DeliveryType::STANDARD !== $this->getDeliveryType()
        ) {
            return false;
        }

        return $this->getCapabilities($packageType)->hasOption($carrier, $packageType, $shipmentOption);
    }

    public function hasInsurance(string $carrier, string $packageType): bool
    {
        return $this->getCapabilities($packageType)
                    ->hasOption($carrier, $packageType, ShipmentOption::INSURANCE);
    }

    /**
     * @todo Phase 5 replaces this with the contract-definition range (FR-000009). It is the last
     *       SDK consignment probe on this form, and it is gone at beta.31.
     * @return int[]
     */
    public function getInsurancePossibilities(string $carrier): array
    {
        try {
            return ConsignmentFactory::createByCarrierName($carrier)
                                     ->getInsurancePossibilities($this->getCountry());
        } catch (\Throwable $e) {
            Logger::notice(sprintf(
                'No insurance amounts for carrier "%s": %s',
                $carrier,
                $e->getMessage()
            ));

            return [];
        }
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
