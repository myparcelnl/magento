<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Block\Sales\NewShipmentForm;
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
use MyParcelNL\Magento\Service\AccountSettings\ContractDefinitions;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\ShipmentOptions\DeliveryList;
use MyParcelNL\Magento\Service\Weight;

/**
 * The shipment options form for one order, or for a bulk selection of one account's orders,
 * resolved from the account's capabilities per carrier and package type.
 *
 * getFormCarriers() is what performs those lookups, so a template must call it before asking
 * hasUnverifiedCapabilities(), which otherwise answers about a render that has not happened.
 * In bulk the order is only a stand-in for its store: no value of it is shown.
 */
class ShipmentOptionsForm implements ArgumentInterface
{
    private Order               $order;
    private DefaultOptions      $defaultOptions;
    private ?NewShipmentForm    $form = null;
    private ShapeLookup         $capabilityLookup;
    private ContractDefinitions $contractDefinitions;
    private Weight              $weightService;
    private StoredAccount       $storedAccount;
    private bool                $bulk = false;
    private bool                $emptyAnswer = false;

    public function __construct(
        Order                  $order,
        CapabilitiesRepository $capabilities,
        ContractDefinitions    $contractDefinitions,
        Weight                 $weightService,
        StoredAccount          $storedAccount,
        bool                   $bulk = false
    ) {
        $this->order               = $order;
        // One lookup per form, not the shared one: hasUnverifiedCapabilities() reads its memo.
        $this->capabilityLookup    = new ShapeLookup($capabilities);
        $this->contractDefinitions = $contractDefinitions;
        $this->defaultOptions      = new DefaultOptions($order);
        $this->weightService       = $weightService;
        $this->storedAccount       = $storedAccount;
        $this->bulk                = $bulk;
    }

    public function isBulk(): bool
    {
        return $this->bulk;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    /**
     * @param string $option 'signature', 'only_recipient'
     */
    public function hasDefaultOption(string $option, string $carrier): bool
    {
        return $this->defaultOptions->hasOptionSet($option, $carrier);
    }

    /**
     * Checked and not changeable: the contract makes the option compulsory, and the API refuses the
     * shipment without it. Anything else the merchant may switch off, an 18+ age check included.
     */
    public function isLocked(string $option, string $carrier, string $packageType): bool
    {
        $value = $this->getCapabilities($packageType)->optionValue($carrier, $packageType, $option);

        return true === ($value['isRequired'] ?? false);
    }

    /** @throws \Exception */
    public function getDefaultInsurance(string $carrier): int
    {
        return $this->defaultOptions->getDefaultInsurance($carrier);
    }

    public function getLabelAmount(): int
    {
        return $this->defaultOptions->getLabelAmount();
    }

    /** The saved weight, else the order's, else the configured default. */
    public function getDigitalStampWeight(): int
    {
        $saved = $this->defaultOptions->getSavedDigitalStampWeight();

        if (null !== $saved) {
            return $saved;
        }

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

    public function getCarrier(): string
    {
        return $this->defaultOptions->getCarrierName();
    }

    public function getCountry(): string
    {
        if (($address = $this->order->getShippingAddress())) {
            return $address->getCountryId();
        }

        return '';
    }

    /** Why the last export of this order failed, or null. */
    public function getExportError(): ?string
    {
        $error = $this->order->getData(Config::FIELD_EXPORT_ERROR);

        return null === $error || '' === $error ? null : (string) $error;
    }

    public function isPickup(): bool
    {
        return DeliveryType::PICKUP_NAME === $this->storedDeliveryTypeName();
    }

    /**
     * Null means the order carries a delivery type we do not recognise, so callers withhold
     * anything that depends on it rather than guessing. Absent is different: no stored type means
     * there was never a choice to honour, so it defaults quietly.
     */
    public function getDeliveryType(): ?int
    {
        $deliveryTypeName = $this->storedDeliveryTypeName();

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

    private function storedDeliveryTypeName(): ?string
    {
        return $this->stored()['deliveryType'] ?? null;
    }

    /** The stored delivery date as Y-m-d, unmoved, or null when the order has none. */
    public function getDeliveryDate(): ?string
    {
        $date      = (string) ($this->stored()['date'] ?? '');
        $timestamp = '' === $date ? false : strtotime($date);

        return false === $timestamp ? null : date('Y-m-d', $timestamp);
    }

    /** @return array<string,int> the length, width and height in cm saved on the order, only the ones set */
    public function getDimensions(): array
    {
        return DeliveryOptions::fromOrderFallback(['physicalProperties' => $this->stored()['physicalProperties'] ?? null])
            ->getDimensions();
    }

    private function stored(): array
    {
        $stored = json_decode((string) $this->order->getData(Config::FIELD_DELIVERY_OPTIONS), true);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @see ShapeLookup::forShape() for what a null package type asks, and what it must not be used for.
     *
     * In bulk the orders' countries differ, so the account's contract answers instead, and the save
     * checks each order against its own country.
     */
    private function getCapabilities(?string $packageType = null): CapabilitySet
    {
        if ($this->bulk) {
            return $this->contractDefinitions->forScope(ScopeInterface::SCOPE_STORES, (int) $this->order->getStoreId());
        }

        return $this->capabilityLookup->forShape(
            (int) $this->order->getStoreId(),
            $this->getCountry(),
            $packageType
        );
    }

    /**
     * Carriers to offer: those the account has a contract for that the module can export, so the
     * same rule as the settings form. A shipment on any other carrier would fail at label time.
     *
     * The live lookup answers permissive on any API failure. Then the stored contract of the order's
     * store answers instead, so a blip does not block label creation. With neither there is no
     * carrier, and the form says so.
     *
     * @return string[]
     */
    public function getCarriers(): array
    {
        $capabilities = $this->getCapabilities();
        $carriers     = $capabilities->isPermissive()
            ? $this->contractDefinitions->forScope(ScopeInterface::SCOPE_STORES, (int) $this->order->getStoreId())->carriers()
            : $capabilities->carriers();

        return array_values(array_filter($carriers, [Carrier::class, 'isExportable']));
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
            ? ShipmentOption::KNOWN_TOGGLES
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
        return $this->getCapabilities($packageType)->hasOption($carrier, $packageType, $shipmentOption);
    }

    /**
     * Labels of the options on for this order that the carrier does not offer for the shipment. The
     * export leaves them off (ShipmentOptionsResolver::dropNotOffered()), so the form says so.
     *
     * @return string[]
     */
    public function getDroppedOptions(string $carrier, string $packageType): array
    {
        $capabilities = $this->getCapabilities($packageType);

        if ($capabilities->isPermissive()) {
            return [];
        }

        $offered = $capabilities->optionsFor($carrier, $packageType);
        $dropped = [];

        foreach (ShipmentOption::KNOWN_TOGGLES as $option) {
            if (! in_array($option, $offered, true) && $this->hasDefaultOption($option, $carrier)) {
                $dropped[] = $this->getNewShipmentForm()->labelFor($option);
            }
        }

        if (! in_array(ShipmentOption::INSURANCE, $offered, true) && 0 < $this->getDefaultInsurance($carrier)) {
            $dropped[] = $this->getNewShipmentForm()->labelFor(ShipmentOption::INSURANCE);
        }

        return $dropped;
    }

    /**
     * Whether any answer this render used was a fallback rather than the account's own.
     *
     * Only meaningful once the form data has been resolved, which is why the template asks for
     * getFormCarriers() first.
     */
    /**
     * The options of a block that are on: the stored or default ones, and the ones the contract
     * makes compulsory. The modal sends these to capabilities as a filter.
     *
     * @return string[]
     */
    public function tickedOptions(string $carrier, string $packageType): array
    {
        return array_values(array_filter(
            $this->getShipmentOptions($carrier, $packageType),
            fn(string $option): bool => $this->hasDefaultOption($option, $carrier)
                                        || $this->isLocked($option, $carrier, $packageType)
        ));
    }

    /**
     * Per option of the block: on, changeable, and greyed out when the carrier offers it for the
     * package type but not with this delivery type and these ticked options. An answer without the
     * carrier falls back to the answer without options, and emptyAnswer() says so.
     *
     * @param string[] $ticked
     *
     * @return array<string,array{checked:bool,disabled:bool,greyed:bool}>
     */
    public function optionStates(string $carrier, string $packageType, string $deliveryType, array $ticked): array
    {
        $answer            = $this->selectionCapabilities($packageType, $deliveryType, $ticked);
        $this->emptyAnswer = ! $answer->isPermissive() && ! in_array($carrier, $answer->carriers(), true);

        if ($this->emptyAnswer) {
            $answer = $this->selectionCapabilities($packageType, $deliveryType, []);
        }

        $states = [];

        foreach ($this->getShipmentOptions($carrier, $packageType) as $option) {
            $offered = $answer->hasOption($carrier, $packageType, $option);

            $states[$option] = [
                'checked'  => $offered && in_array($option, $ticked, true),
                'disabled' => ! $offered || $this->isLocked($option, $carrier, $packageType),
                'greyed'   => ! $offered,
            ];
        }

        return $states;
    }

    /**
     * The states before the modal's first refresh: on when default or locked, nothing greyed. It
     * asks no capabilities per delivery type, so opening the form costs no extra request per block.
     *
     * @return array<string,array{checked:bool,disabled:bool,greyed:bool}>
     */
    public function initialOptionStates(string $carrier, string $packageType): array
    {
        $ticked = $this->tickedOptions($carrier, $packageType);
        $states = [];

        foreach ($this->getShipmentOptions($carrier, $packageType) as $option) {
            $states[$option] = [
                'checked'  => in_array($option, $ticked, true),
                'disabled' => $this->isLocked($option, $carrier, $packageType),
                'greyed'   => false,
            ];
        }

        return $states;
    }

    /** Whether the last optionStates() answer had no result for the ticked options. */
    public function emptyAnswer(): bool
    {
        return $this->emptyAnswer;
    }

    /**
     * The delivery types capabilities allow with the ticked options. Every type the modal offers
     * while capabilities are unknown.
     *
     * @param string[] $ticked
     *
     * @return string[] module delivery type names
     */
    public function allowedDeliveryTypes(string $carrier, string $packageType, array $ticked): array
    {
        $answer = $this->selectionCapabilities($packageType, null, $ticked);

        if ($answer->isPermissive()) {
            return [
                DeliveryType::STANDARD_NAME,
                DeliveryType::MORNING_NAME,
                DeliveryType::EVENING_NAME,
                DeliveryType::EXPRESS_NAME,
                DeliveryType::PICKUP_NAME,
            ];
        }

        return $answer->deliveryTypesFor($carrier, $packageType);
    }

    /** @return array<string,string[]> per option of the block, the options capabilities say it requires */
    public function requiresMap(string $carrier, string $packageType): array
    {
        $requires = [];

        foreach ($this->getShipmentOptions($carrier, $packageType) as $option) {
            $required = $this->getCapabilities($packageType)->requiresFor($carrier, $packageType, $option);

            if ([] !== $required) {
                $requires[$option] = $required;
            }
        }

        return $requires;
    }

    /** The stored delivery as a DeliveryList value; "Standard, no date" when none is stored. */
    public function storedDeliveryValue(): string
    {
        return DeliveryList::valueOf($this->storedDeliveryTypeName(), $this->getDeliveryDate())
               ?? DeliveryType::STANDARD_NAME . '|';
    }

    public function storedPickupLocationCode(): ?string
    {
        $code = $this->stored()['pickupLocation']['location_code'] ?? null;

        return null === $code || '' === $code ? null : (string) $code;
    }

    /** The stored delivery in one line, for the New Shipment summary. */
    public function deliverySummary(): string
    {
        $type = $this->storedDeliveryTypeName() ?? DeliveryType::STANDARD_NAME;

        if (DeliveryType::PICKUP_NAME === $type) {
            return DeliveryList::labelFor($type) . ' · ' . (string) ($this->stored()['pickupLocation']['location_name'] ?? '');
        }

        $date = $this->getDeliveryDate();

        return DeliveryList::labelFor($type) . (null === $date ? '' : ' · ' . date('d-m-Y', (int) strtotime($date)));
    }

    /**
     * What capabilities allow for this selection. In bulk the orders differ, so the contract answers.
     *
     * @param string[] $ticked
     */
    private function selectionCapabilities(string $packageType, ?string $deliveryType, array $ticked): CapabilitySet
    {
        if ($this->bulk) {
            return $this->getCapabilities($packageType);
        }

        return $this->capabilityLookup->forShape(
            (int) $this->order->getStoreId(),
            $this->getCountry(),
            $packageType,
            null,
            $deliveryType,
            $ticked
        );
    }

    public function hasUnverifiedCapabilities(): bool
    {
        return $this->capabilityLookup->answeredPermissively();
    }

    /**
     * The whole form, resolved: carriers, their package types, and per package type the options,
     * insurance and digital-stamp weights.
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
                    'human'     => self::packageTypeHuman($packageTypeName),
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
     * The ranges are DigitalStampWeight's, shared with the admin default-weight setting.
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
     * Bulk: every package type the account's carriers offer. Which one an order may take is
     * checked per order on save.
     *
     * @return array<string,string> name => label
     */
    public function getBulkPackageTypes(): array
    {
        $types = [];

        foreach ($this->getCarriers() as $carrier) {
            foreach ($this->getPackageTypes($carrier) as $name) {
                $id = PackageType::toIdOrNull($name);

                if (null !== $id) {
                    $types[$name] = (string) __(self::packageTypeHuman($name));
                }
            }
        }

        return $types;
    }

    /**
     * Bulk: every option the account offers on any carrier and package type, insurance excluded.
     *
     * @return array<string,string> name => label
     */
    public function getBulkOptions(): array
    {
        $options = [];

        foreach ($this->getFormCarriers() as $carrier) {
            foreach ($carrier['packageTypes'] as $packageType) {
                foreach ($packageType['options'] as $option) {
                    $options[$option] = $this->getNewShipmentForm()->labelFor($option);
                }
            }
        }

        return $options;
    }

    /**
     * What the export would send now, as label => value lines for a summary.
     *
     * @return array<string,string>
     */
    public function summary(): array
    {
        $carrier     = $this->getCarrier();
        $packageType = $this->getPackageTypeName();
        $enabled     = [];

        foreach ($this->getShipmentOptions($carrier, $packageType) as $option) {
            if ($this->hasDefaultOption($option, $carrier)) {
                $enabled[] = $this->getNewShipmentForm()->labelFor($option);
            }
        }

        $summary = [
            (string) __('Carrier')      => Carrier::humanFor($carrier),
            (string) __('Package type') => (string) __(self::packageTypeHuman($packageType)),
            (string) __('Options')      => [] === $enabled ? (string) __('None') : implode(', ', $enabled),
        ];

        if ($this->hasInsurance($carrier, $packageType)) {
            $insurance = $this->getDefaultInsurance($carrier);
            $summary[(string) __('Insured up to:')] = 0 === $insurance ? (string) __('None') : '€ ' . $insurance;
        }

        $summary[(string) __('Label amount:')] = (string) $this->getLabelAmount();

        $summary[(string) __('Delivery')] = $this->deliverySummary();

        if ([] !== $this->getDimensions()) {
            $summary[(string) __('Dimensions (cm)')] = implode(' x ', $this->getDimensions());
        }

        return $summary;
    }

    public function getNewShipmentForm(): NewShipmentForm
    {
        return $this->form ?? ($this->form = new NewShipmentForm());
    }

    public function hasOrderV1(): bool
    {
        return $this->storedAccount->hasOrderV1ForStore((int) $this->order->getStoreId());
    }

    /** The untranslated label of a package type name; the name itself when it has none. */
    private static function packageTypeHuman(string $name): string
    {
        $id = PackageType::toIdOrNull($name);

        return null === $id ? $name : (NewShipmentForm::PACKAGE_TYPE_HUMAN_MAP[$id] ?? $name);
    }
}
