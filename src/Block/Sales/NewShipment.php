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
use MyParcelNL\Magento\Model\Shipment\Capabilities\InsuranceRange;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Model\Shipment\Capabilities\ShapeLookup;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\DigitalStampWeight;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;

/**
 * The admin New Shipment form, resolved from the account's capabilities per carrier and package type.
 *
 * getFormCarriers() is what performs those lookups, so the template must call it before asking
 * hasUnverifiedCapabilities(), which otherwise answers about a render that has not happened.
 */
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

    private ShapeLookup $capabilityLookup;

    private Weight $weightService;

    private Config $configService;

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

        $this->capabilityLookup = new ShapeLookup($objectManager->get(CapabilitiesRepository::class));

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

    /** @see ShapeLookup::forShape() for what a null package type asks, and what it must not be used for. */
    private function getCapabilities(?string $packageType = null): CapabilitySet
    {
        return $this->capabilityLookup->forShape(
            (int) $this->order->getStoreId(),
            $this->getCountry(),
            $packageType
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
        // getDeliveryType() answers null for a stored type the module does not know, and
        // allowedForDeliveryType() reads that as standard, so name it explicitly instead.
        $deliveryTypeId = $this->getDeliveryType();
        $deliveryType   = null === $deliveryTypeId ? 'unknown' : DeliveryType::nameFromIdOrNull($deliveryTypeId);

        if (! ShipmentOption::allowedForDeliveryType($shipmentOption, $deliveryType)) {
            return false;
        }

        return $this->getCapabilities($packageType)->hasOption($carrier, $packageType, $shipmentOption);
    }

    /**
     * Whether any answer this render used was a fallback rather than the account's own.
     *
     * Only meaningful once the form data has been resolved, which is why the template asks for
     * getFormCarriers() first.
     */
    public function hasUnverifiedCapabilities(): bool
    {
        return $this->capabilityLookup->answeredPermissively();
    }

    /**
     * The whole form, resolved: carriers, their package types, and per package type the options,
     * insurance and digital-stamp weights.
     *
     * Built here rather than in the template so the template is a renderer, and so the
     * unverified-capabilities notice can be rendered above a form whose data is already known.
     *
     * @return array<int,array{name:string,human:string,packageTypes:array}>
     */
    public function getFormCarriers(): array
    {
        $carriers = [];

        foreach ($this->getCarriers() as $carrierName) {
            $packageTypes = [];

            foreach ($this->getPackageTypes($carrierName) as $packageTypeName) {
                $packageTypeId = PackageType::toIdOrNull($packageTypeName);

                if (null === $packageTypeId) {
                    // Nothing to submit: the radio's value would resolve to no id on the way out.
                    continue;
                }

                $packageTypes[] = [
                    'name'      => $packageTypeName,
                    'id'        => $packageTypeId,
                    'human'     => NewShipmentForm::PACKAGE_TYPE_HUMAN_MAP[$packageTypeId] ?? $packageTypeName,
                    'options'   => $this->getShipmentOptions($carrierName, $packageTypeName),
                    'insurance' => $this->hasInsurance($carrierName, $packageTypeName)
                        ? $this->insuranceField($carrierName, $packageTypeName)
                        : null,
                    'weights'   => PackageType::DIGITAL_STAMP === $packageTypeId
                        ? $this->getDigitalStampWeightOptions()
                        : null,
                ];
            }

            $carriers[] = [
                'name'         => $carrierName,
                'human'        => Carrier::humanFor($carrierName),
                'packageTypes' => $packageTypes,
            ];
        }

        return $carriers;
    }

    /**
     * Digital stamp weight ranges, with the one the order falls in pre-selected.
     *
     * The ranges are DigitalStampWeight's, shared with the admin default-weight setting. This form
     * used to hold its own list, which still offered the two values the setting had retired.
     *
     * @return array<int,array{value:int,label:\Magento\Framework\Phrase,selected:bool}>
     */
    public function getDigitalStampWeightOptions(): array
    {
        $selected = DigitalStampWeight::valueFor($this->getDigitalStampWeight());

        return array_map(
            static function (array $option) use ($selected): array {
                return [
                    'value'    => $option['value'],
                    'label'    => $option['label'],
                    // NO_STANDARD_WEIGHT is never the answer valueFor() gives, so it stays unselected.
                    'selected' => $option['value'] === $selected,
                ];
            },
            DigitalStampWeight::options()
        );
    }

    public function hasInsurance(string $carrier, string $packageType): bool
    {
        return $this->getCapabilities($packageType)
                    ->hasOption($carrier, $packageType, ShipmentOption::INSURANCE);
    }

    /**
     * The amount field's bounds and starting value.
     *
     * Asked with the package type set, so the bound is this shape's rather than a union across
     * package types. Null bounds render an unbounded field: the export path clamps against
     * the real destination anyway, and refusing to offer insurance because a lookup failed is exactly
     * what must not happen.
     *
     * @return array{default: int, min: int|null, max: int|null, floor: int, required: bool}
     */
    private function insuranceField(string $carrier, string $packageType): array
    {
        $range = InsuranceRange::fromOptionValue(
            $this->getCapabilities($packageType)
                 ->optionValue($carrier, $packageType, ShipmentOption::INSURANCE)
        );

        return [
            'default'  => $this->getDefaultInsurance($carrier),
            'min'      => $range ? $range->min() : null,
            'max'      => $range ? $range->max() : null,
            // Zero is enterable unless the contract makes insurance compulsory; the rule lives on
            // InsuranceRange so the form and the settings screen cannot disagree about it.
            'floor'    => $range ? $range->lowestAccepted() : 0,
            'required' => $range && $range->isRequired(),
        ];
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
