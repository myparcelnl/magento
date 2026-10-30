<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Model\Shipment\Carrier as ShipmentCarrier;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Capabilities\ShapeLookup;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\PackageTypeCandidates;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Magento\Service\AccountSettings\ContractDefinitions;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\DropOffSettings;
use MyParcelNL\Magento\Service\NeedsQuoteProps;
use MyParcelNL\Magento\Service\PackageTypeResolver;
use MyParcelNL\Magento\Service\Tax;
use MyParcelNL\Sdk\Services\CountryCodes;

/**
 * The delivery options the checkout widget is offered for the current quote.
 *
 * The one place a package type is ruled out because an option the order forces cannot ride on it
 * (checkPackageType()); the API stays the validator. A permissive capability set restricts nothing.
 */
class Checkout
{
    use NeedsQuoteProps;

    public const MAGENTO_CARRIER_CODE_FREE_SHIPPING = 'freeshipping';

    private Tax                   $tax;
    private Config                $config;
    private DeliveryCosts         $deliveryCosts;
    private PackageTypeResolver   $packageTypes;
    private CartShippingRules     $cartRules;
    private Quote                 $quote;
    private StoreManagerInterface $storeManager;
    private ShapeLookup           $capabilityLookup;
    private ContractDefinitions   $contractDefinitions;
    private StoredAccount         $storedAccount;
    private DropOffSettings       $dropOffSettings;

    /** The quote's store. Every config read this class delegates carries it, rather than relying on
     *  the ambient store of the request. */
    private int $storeId;

    /** @var string[]|null the carriers with delivery or pickup on; getDeliveryOptions() asks three times */
    private ?array $activeCarriers = null;

    /**
     * Checkout constructor.
     *
     * @param Tax                   $tax
     * @param Config                $config
     * @param DeliveryCosts         $deliveryCosts
     * @param PackageTypeResolver   $packageTypes
     * @param CartShippingRules     $cartRules
     * @param StoreManagerInterface $storeManager
     * @param ShapeLookup           $capabilityLookup
     * @param ContractDefinitions   $contractDefinitions
     * @param StoredAccount         $storedAccount
     * @param DropOffSettings       $dropOffSettings
     */
    public function __construct(
        Tax                   $tax,
        Config                $config,
        DeliveryCosts         $deliveryCosts,
        PackageTypeResolver   $packageTypes,
        CartShippingRules     $cartRules,
        StoreManagerInterface $storeManager,
        ShapeLookup           $capabilityLookup,
        ContractDefinitions   $contractDefinitions,
        StoredAccount         $storedAccount,
        DropOffSettings       $dropOffSettings
    )
    {
        $this->tax                 = $tax;
        $this->config              = $config;
        $this->deliveryCosts       = $deliveryCosts;
        $this->packageTypes        = $packageTypes;
        $this->cartRules           = $cartRules;
        $this->storeManager        = $storeManager;
        $this->capabilityLookup    = $capabilityLookup;
        $this->contractDefinitions = $contractDefinitions;
        $this->storedAccount       = $storedAccount;
        $this->dropOffSettings     = $dropOffSettings;
        $this->quote               = $this->getQuoteFromCurrentSession();
        // Cast kept: a quote with no store id resolves as store 0, which is not the same as null.
        $this->storeId             = (int) $this->quote->getStoreId();
    }

    /**
     * Get settings for MyParcel delivery options.
     * Warning: as a side effect this method will set the free shipping availability in the session, when an address is provided.
     *
     * @param array $forAddress associative array holding the latest address from the client
     *
     * @return array
     * @throws NoSuchEntityException|LocalizedException
     */
    public function getDeliveryOptions(array $forAddress = []): array
    {
        $country = $forAddress['countryId'] ?? null;

        if ($country
            && ! $this->isFakeRequest($forAddress)
            && in_array($country, CountryCodes::ALL, true)
        ) {
            $this->setFreeShippingAvailability($this->quote, $forAddress);
        } else {
            $country = $this->quote->getShippingAddress()->getCountryId() ?? $this->config->getConfigValue('general/country/default') ?? CountryCode::CC_NL;
        }

        $packageType = $this->getPackageType($country);

        $data = [
            'carrierCode'     => Carrier::CODE,
            'useFreeShipping' => '1' === $this->config->getGeneralConfig('matrix/use_free_shipping'),
            'config'          => array_merge(
                $this->getGeneralData(),
                $this->getDeliveryData($packageType, $country),
                ['packageType' => $packageType]
            ),
            'strings'         => $this->getDeliveryOptionsStrings(),
            'forAddress'      => $forAddress,
        ];

        return [
            'root' => [
                'version' => $this->config->getVersion(),
                'data'    => $data,
            ],
        ];
    }

    /**
     * Get general data
     *
     * @return array
     * @throws NoSuchEntityException|LocalizedException
     */
    private function getGeneralData(): array
    {
        $activeCarriers = $this->getActiveCarriers();
        $carrierPath    = isset($activeCarriers[0]) ? Config::carrierPath($activeCarriers[0]) : null;
        $deliveryDaysWindow = $this->dropOffSettings->deliveryDaysWindow();

        return [
            'platform'                          => $this->propositionName(),
            'currency'                          => $this->storeManager->getStore()->getCurrentCurrency()->getCode(),
            'proxyCapabilities'                 => $this->storeManager->getStore()->getBaseUrl() . 'myparcel/proxy/core/shipments/capabilities',
            'showDeliveryDate'                  => $deliveryDaysWindow > 0,
            'deliveryDaysWindow'                => $deliveryDaysWindow,
            'dropOffDelay'                      => $this->getDropOffDelay(),
            'pickupLocationsDefaultView'        => $this->config->getConfigValue(Config::XML_PATH_GENERAL . 'shipping_methods/pickup_locations_view'),
            'allowPickupLocationsViewSelection' => $this->config->getBoolConfig(Config::XML_PATH_GENERAL, 'shipping_methods/pickup_locations_view_change_allowed'),
            'showPriceSurcharge'                => $this->config->getConfigValue(Config::XML_PATH_GENERAL . 'shipping_methods/delivery_options_prices') === PriceDeliveryOptionsView::SURCHARGE,
            'excludeParcelLockers'              => $this->isExcludeParcelLockersActive($carrierPath),
            'compactView'                       => $this->config->getBoolConfig(Config::XML_PATH_GENERAL, 'shipping_methods/compact_view'),
            'popUpMap'                          => $this->config->getBoolConfig(Config::XML_PATH_GENERAL, 'shipping_methods/pop_up_map'),
        ];
    }

    /** The widget needs a proposition, so a store without a listed one gets the default. */
    private function propositionName(): string
    {
        return ($this->storedAccount->propositionForStore($this->storeId) ?? Proposition::default())->getName();
    }

    /**
     * Get general data
     *
     * @param string $country
     * @return string
     */
    private function getPackageType(string $country): string
    {
        $packageType    = PackageType::PACKAGE_NAME;
        $activeCarriers = $this->getActiveCarriers();

        foreach ($activeCarriers as $carrier) {
            $tentativePackageType = $this->checkPackageType($carrier, $country);

            switch ($tentativePackageType) {
                case PackageType::DIGITAL_STAMP_NAME:
                    return PackageType::DIGITAL_STAMP_NAME;
                case PackageType::MAILBOX_NAME:
                    $packageType = PackageType::MAILBOX_NAME;
                    break;
                case PackageType::PACKAGE_SMALL_NAME:
                    return PackageType::PACKAGE_SMALL_NAME;
            }
        }

        return $packageType;
    }

    /**
     * Get delivery data
     *
     * @param string $packageType
     * @param string $country
     * @return array
     */
    private function getDeliveryData(string $packageType, string $country): array
    {
        $myParcelConfig = [];
        $activeCarriers = $this->getActiveCarriers();
        $showTotalPrice = $this->config->getConfigValue(Config::XML_PATH_GENERAL . 'shipping_methods/delivery_options_prices') === PriceDeliveryOptionsView::TOTAL;

        $quote           = $this->quote;
        $items           = $quote->getAllItems();
        $hidesForProduct = $this->cartRules->hidesDeliveryOptions($items);

        foreach ($activeCarriers as $carrierName) {
            $carrierPath = Config::carrierPath($carrierName);
            // Per carrier: the carrier's own default option setting can force an option.
            $caps        = $this->getCapabilities(
                $country,
                $packageType,
                $this->cartRules->forcedCheckoutOptions($items, $carrierPath, $this->storeId)
            );
            $basePrices  = [];

            // the matrix can price each delivery type differently, the fees come on top of that
            foreach (DeliveryType::pricedPerTypeNames() as $deliveryType) {
                $basePrices[$deliveryType] = $this->deliveryCosts->getBasePriceForClient($quote, $carrierName, $packageType, $country, $deliveryType);
            }

            $basePrice = $basePrices[DeliveryType::STANDARD_NAME];

            $canHaveSameDay          = $caps->hasOption($carrierName, $packageType, ShipmentOption::SAME_DAY_DELIVERY);
            $canHaveMorning          = $caps->hasDeliveryType($carrierName, $packageType, DeliveryType::MORNING_NAME);
            $canHaveEvening          = $caps->hasDeliveryType($carrierName, $packageType, DeliveryType::EVENING_NAME);
            $canHaveExpress          = $caps->hasDeliveryType($carrierName, $packageType, DeliveryType::EXPRESS_NAME);
            $canHavePickup           = $caps->hasDeliveryType($carrierName, $packageType, DeliveryType::PICKUP_NAME);
            $canHaveSignature        = $caps->hasOption($carrierName, $packageType, ShipmentOption::SIGNATURE);
            $canHaveCollect          = $caps->hasOption($carrierName, $packageType, ShipmentOption::COLLECT);
            $canHaveReceiptCode      = $caps->hasOption($carrierName, $packageType, ShipmentOption::RECEIPT_CODE);
            $canHavePriorityDelivery = $caps->hasOption($carrierName, $packageType, ShipmentOption::PRIORITY_DELIVERY);
            $canHaveOnlyRecipient    = $caps->hasOption($carrierName, $packageType, ShipmentOption::ONLY_RECIPIENT);

            $addBasePrice        = ($showTotalPrice) ? $basePrice : 0;
            $sameDayFee          = $canHaveSameDay ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/same_day_delivery_fee'), $quote) + $addBasePrice : 0;
            $signatureFee        = $canHaveSignature ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/signature_fee'), $quote) : 0;
            $collectFee          = $canHaveCollect ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/collect_fee'), $quote) : 0;
            $receiptCodeFee      = $canHaveReceiptCode ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/receipt_code_fee'), $quote) : 0;
            $priorityDeliveryFee = $canHavePriorityDelivery ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'mailbox/priority_delivery_fee'), $quote) : 0;
            $onlyRecipientFee    = $canHaveOnlyRecipient ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/only_recipient_fee'), $quote) : 0;

            // The price per matrix-priced delivery type, its name mapped onto a price{Name}Delivery
            // key, with the fee from the type's own config group on top — the SDK's type list drives
            // this (see DeliveryType::pricedPerTypeNames()), so a new type is priced by default.
            // Standard has no fee group of its own.
            $deliveryTypePrices = [];
            foreach (DeliveryType::pricedPerTypeNames() as $deliveryType) {
                $canHaveType = DeliveryType::DEFAULT_NAME === $deliveryType
                               || $caps->hasDeliveryType($carrierName, $packageType, $deliveryType);
                $typeFee     = DeliveryType::DEFAULT_NAME === $deliveryType
                    ? 0.0
                    : $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, "$deliveryType/fee"), $quote);

                $deliveryTypePrices[self::getPriceKeyFromDeliveryType($deliveryType)] = $canHaveType
                    ? self::deliveryTypePrice($basePrices, $deliveryType, $typeFee, $showTotalPrice)
                    : 0;
            }

            $allowPickup           = $this->config->getBoolConfig($carrierPath, 'pickup/active');
            $allowStandardDelivery = $this->config->getBoolConfig($carrierPath, 'delivery/active');
            $allowMorningDelivery  = $canHaveMorning && $this->config->getBoolConfig($carrierPath, 'morning/active');
            $allowEveningDelivery  = $canHaveEvening && $this->config->getBoolConfig($carrierPath, 'evening/active');
            $allowExpressDelivery  = $canHaveExpress && $this->config->getBoolConfig($carrierPath, 'express/active');
            // One package type serves the whole checkout, so a type another carrier can carry a
            // forced option on still reaches this one. $caps is filtered by the forced options, so
            // a carrier that cannot carry them on this package type has no result for it.
            $allowDeliveryOptions  = $caps->hasPackageType($carrierName, $packageType)
                                     && ! $hidesForProduct
                                     && ($allowPickup || $allowStandardDelivery || $allowMorningDelivery || $allowEveningDelivery);

            if ($allowDeliveryOptions && $packageType === PackageType::MAILBOX_NAME) {
                $allowDeliveryOptions = $this->config->getBoolConfig($carrierPath, 'mailbox/active')
                                        && $this->packageTypes->maxMailboxWeight($carrierPath, $this->storeId)
                                           >= $this->packageTypes->cartWeight($items);
            }

            if (! $allowDeliveryOptions) {
                // Widget v7 ignores allowDeliveryOptions; a carrier missing from carrierSettings is not shown.
                continue;
            }

            $myParcelConfig['carrierSettings'][$carrierName] = [
                'allowDeliveryOptions'  => $allowDeliveryOptions,
                'allowStandardDelivery' => $allowStandardDelivery,
                'allowSignature'        => $canHaveSignature && $this->config->getBoolConfig($carrierPath, 'delivery/signature_active'),
                'allowCollect'          => $canHaveCollect && $this->config->getBoolConfig($carrierPath, 'delivery/collect_active'),
                'allowReceiptCode'      => $canHaveReceiptCode && $this->config->getBoolConfig($carrierPath, 'delivery/receipt_code_active'),
                'allowOnlyRecipient'    => $canHaveOnlyRecipient && $this->config->getBoolConfig($carrierPath, 'delivery/only_recipient_active'),
                'allowPriorityDelivery' => $canHavePriorityDelivery && $this->cartRules->allowsPriorityDelivery($items, $carrierPath, $this->storeId),
                'allowMorningDelivery'  => $allowMorningDelivery,
                'allowEveningDelivery'  => $allowEveningDelivery,
                'allowPickupLocations'  => $canHavePickup && $this->isPickupAllowed($carrierPath, $country),
                // Not a capability the API reports, so never offered, whatever a stored row says.
                'allowMondayDelivery'   => false,
                'allowSameDayDelivery'  => $canHaveSameDay && $this->config->getBoolConfig($carrierPath, 'delivery/same_day_delivery_active'),
                'allowExpressDelivery'  => $allowExpressDelivery,

                'dropOffDays' => $this->dropOffSettings->dropOffDays($carrierPath),

                'priceSignature'        => $signatureFee,
                'priceCollect'          => $collectFee,
                'priceReceiptCode'      => $receiptCodeFee,
                'priceOnlyRecipient'    => $onlyRecipientFee,
                'pricePriorityDelivery' => $priorityDeliveryFee,
                'priceSameDayDelivery'  => $sameDayFee,
                // because of how the delivery options work, we need to put the correctly calculated price in separate keys:
                'pricePackageTypeMailbox'      => $basePrice,
                'pricePackageTypeDigitalStamp' => $basePrice,
                'pricePackageTypePackageSmall' => $basePrice,
                // if you want separate package type prices, get them with this: $this->deliveryCosts->getBasePrice($this->quote, $carrierName, $packageType, $country);
            ] + $deliveryTypePrices;
        }

        return $myParcelConfig;
    }

    /**
     * The widget takes pickup as pricePickup, every other delivery type as price{Name}Delivery.
     */
    private static function getPriceKeyFromDeliveryType(string $deliveryType): string
    {
        if (DeliveryType::PICKUP_NAME === $deliveryType) {
            return 'pricePickup';
        }

        return 'price' . str_replace('_', '', ucwords($deliveryType, '_')) . 'Delivery';
    }

    /**
     * The price the widget shows for a delivery type: the total, or the surcharge on standard delivery. Either way
     * standard delivery plus the surcharge comes to the total, which is never negative.
     *
     * @param array<string, float> $basePrices by delivery type name, holding at least standard
     */
    private static function deliveryTypePrice(array $basePrices, string $deliveryType, float $fee, bool $showTotalPrice): float
    {
        $standard = $basePrices[DeliveryType::STANDARD_NAME];

        if ($showTotalPrice) {
            return max(0.0, $basePrices[$deliveryType] + $fee);
        }

        // the difference first, so equal base prices leave the fee exactly as it was
        return max(-$standard, $basePrices[$deliveryType] - $standard + $fee);
    }

    /**
     * @see ShapeLookup::forShape() for what a null package type asks, and what it must not be used for.
     *
     * @param string[] $forced the forced ShipmentOption::LIMIT_CHECKOUT_OPTIONS, sent as a filter
     */
    private function getCapabilities(string $country, ?string $packageType = null, array $forced = []): CapabilitySet
    {
        return $this->capabilityLookup->forShape((int) $this->quote->getStoreId(), $country, $packageType, null, null, $forced);
    }

    /**
     * The exportable carriers in this store's contract with delivery or pickup switched on. None when
     * the contract could not be read: a checkout without carriers is the failure we accept, and the
     * settings form says why.
     *
     * @return string[]
     */
    public function getActiveCarriers(): array
    {
        if (null !== $this->activeCarriers) {
            return $this->activeCarriers;
        }

        $contracted = $this->contractDefinitions->forScope(ScopeInterface::SCOPE_STORES, $this->storeId)->carriers();

        $carriers = [];
        foreach (array_filter($contracted, [ShipmentCarrier::class, 'isExportable']) as $carrier) {
            $path = Config::carrierPath($carrier);

            if ($this->config->getBoolConfig($path, 'delivery/active') ||
                $this->config->getBoolConfig($path, 'pickup/active')
            ) {
                $carriers[] = $carrier;
            }
        }

        return $this->activeCarriers = $carriers;
    }

    /**
     * Get delivery options strings
     *
     * @return array
     */
    private function getDeliveryOptionsStrings(): array
    {
        return [
            'deliveryTitle'         => $this->config->getGeneralConfig('delivery_titles/delivery_title') ?: __('Delivery Options'),
            'headerDeliveryOptions' => $this->config->getGeneralConfig('delivery_titles/header_delivery_options') ?: __('Delivery options'),

            'deliveryStandardTitle' => $this->config->getGeneralConfig('delivery_titles/standard_delivery_title') ?: __('Standard Delivery'),
            'deliveryMorningTitle'  => $this->config->getGeneralConfig('delivery_titles/morning_title') ?: __('Morning Delivery'),
            'deliveryEveningTitle'  => $this->config->getGeneralConfig('delivery_titles/evening_title') ?: __('Evening Delivery'),
            'deliveryPickupTitle'   => $this->config->getGeneralConfig('delivery_titles/pickup_title') ?: __('Pickup locations'),
            'deliverySameDayTitle'  => $this->config->getGeneralConfig('delivery_titles/same_day_title') ?: __('Same day Delivery'),

            'priorityDeliveryTitle' => $this->config->getGeneralConfig('delivery_titles/priority_delivery_title') ?: __('Priority delivery'),
            'saturdayDeliveryTitle' => $this->config->getGeneralConfig('delivery_titles/saturday_title') ?: __('Saturday delivery'),

            'signatureTitle'     => $this->config->getGeneralConfig('delivery_titles/signature_title') ?: __('Signature'),
            'onlyRecipientTitle' => $this->config->getGeneralConfig('delivery_titles/only_recipient_title') ?: __('Only Recipient'),
            'hideSenderTitle'    => $this->config->getGeneralConfig('delivery_titles/hide_sender_title') ?: __('Hide Sender'),

            'packageTypeMailbox'      => $this->config->getGeneralConfig('delivery_titles/mailbox_title') ?: __('Mailbox'),
            'packageTypeDigitalStamp' => $this->config->getGeneralConfig('delivery_titles/digital_stamp_title') ?: __('Digital Stamp'),
            'packageTypePackageSmall' => $this->config->getGeneralConfig('delivery_titles/package_small_title') ?: __('Packet'),

            'pickupTitle'               => $this->config->getGeneralConfig('delivery_titles/pickup_title') ?: __('Pickup locations'),
            'pickUpFrom'                => __('Pick up from'),
            'pickupLocationsListButton' => $this->config->getGeneralConfig('delivery_titles/pickup_list_button_title') ?: __('List'),
            'pickupLocationsMapButton'  => $this->config->getGeneralConfig('delivery_titles/pickup_map_button_title') ?: __('Map'),
            'list'                      => $this->config->getGeneralConfig('delivery_titles/pickup_list_button_title') ?: __('List'),
            'map'                       => $this->config->getGeneralConfig('delivery_titles/pickup_map_button_title') ?: __('Map'),
            'compactBackToOverview'     => $this->config->getGeneralConfig('delivery_titles/compact_back_to_overview_title') ?: __('Back to overview'),
            'compactDelivery'           => $this->config->getGeneralConfig('delivery_titles/compact_delivery_title') ?: __('Delivery'),
            'compactPickup'             => $this->config->getGeneralConfig('delivery_titles/compact_pickup_title') ?: __('Pickup'),
            'popUpMapTitle'             => $this->config->getGeneralConfig('delivery_titles/pop_up_map_title') ?: __('Pickup location'),
            'popUpMapOpen'              => $this->config->getGeneralConfig('delivery_titles/pop_up_map_open_title') ?: __('Open map'),
            'popUpMapConfirm'           => $this->config->getGeneralConfig('delivery_titles/pop_up_map_confirm_title') ?: __('Confirm'),

            'parcelLocker'      => __('Parcel locker'),
            'openingHours'      => __('Opening hours'),
            'showMoreHours'     => __('Show more opening hours'),
            'showMoreLocations' => __('Show more locations'),
            'loadMore'          => __('Load more'),
            'options'           => __('Options'),

            'ecoFriendly' => __('Most sustainable'),
            'discount'    => __('Discount'),
            'free'        => __('Free'),
            'from'        => __('From'),
            'closed'      => __('Closed'),

            'addressNotFound' => __('Address details are not entered'),
            'postalCode'      => __('Postal code'),
            'street'          => __('Street'),
            'city'            => __('City'),
            'cc'              => __('Country'),

            'deliveryMomentNotPossible'  => __('{deliveryType} (date/time selection not available)'),
            'noDeliveryOptionsAvailable' => __('No delivery options available.'),

            'error3212' => __('error3212'),
            'error3224' => __('error3224'),
            'error3501' => __('error3501'),
            'error3505' => __('error3505'),
            'error3506' => __('error3506'),
            'error3516' => __('error3516'),
            'error3517' => __('error3517'),
            'error3707' => __('error3707'),
            'error3728' => __('error3728'),
        ];
    }

    /**
     * @param string $carrierName
     * @param string $country
     *
     * @return string
     */
    public function checkPackageType(string $carrierName, string $country): string
    {
        $carrierPath = Config::carrierPath($carrierName);
        $products    = $this->quote->getAllItems();

        // The package-type-agnostic question: this method is what decides the package type, so
        // there is none to narrow by yet. The forced options filter it, so a package type that
        // cannot carry them is not in the answer. A permissive answer restricts nothing.
        $caps = $this->getCapabilities(
            $country,
            null,
            $this->cartRules->forcedCheckoutOptions($products, $carrierPath, $this->storeId)
        );

        $canHaveDigitalStamp = $caps->hasPackageType($carrierName, PackageType::DIGITAL_STAMP_NAME);
        $canHaveMailbox      = $caps->hasPackageType($carrierName, PackageType::MAILBOX_NAME);
        $canHavePackageSmall = $caps->hasPackageType($carrierName, PackageType::PACKAGE_SMALL_NAME);

        // Abroad the international toggle decides, so a carrier switched off at home can still ship
        // a mailbox. && short-circuits per entry, so a config key is not read once the capability
        // has already said no.
        $mailboxActiveKey = $this->storedAccount->homeCountryForStore($this->storeId) === $country
            ? 'mailbox/active'
            : 'mailbox/international_active';

        $candidates = PackageTypeCandidates::none();

        foreach ([
            PackageType::DIGITAL_STAMP_NAME => $canHaveDigitalStamp && $this->config->getBoolConfig($carrierPath, 'digital_stamp/active'),
            PackageType::MAILBOX_NAME       => $canHaveMailbox && $this->config->getBoolConfig($carrierPath, $mailboxActiveKey),
            PackageType::PACKAGE_SMALL_NAME => $canHavePackageSmall && $this->config->getBoolConfig($carrierPath, 'package_small/active'),
        ] as $packageTypeName => $isCandidate) {
            if ($isCandidate) {
                $candidates = $candidates->with($packageTypeName);
            }
        }

        return $this->packageTypes->resolve($products, $carrierName, $country, $candidates, $this->storeId);
    }

    /** With no active carrier there is no carrier age check to ask, only the general and product rules. */
    private function isExcludeParcelLockersActive(?string $carrierPath): bool
    {
        $products = $this->quote->getAllItems();

        return $this->cartRules->excludesParcelLockers($products, $carrierPath, $this->storeId);
    }

    /** The longest of the configured drop-off delay and the delay a product in the cart sets. */
    private function getDropOffDelay(): int
    {
        $products     = $this->quote->getAllItems();
        $productDelay = (int) $this->cartRules->dropOffDelay($products);
        $configDelay  = $this->dropOffSettings->dropOffDelay();

        return max($productDelay, $configDelay);
    }

    /**
     * @param string $carrier
     * @param string $country
     * @return bool
     */
    private function isPickupAllowed(string $carrier, string $country): bool
    {
        $pickupEnabled = PackageType::PACKAGE_NAME === $this->getPackageType($country)
                         && $this->config->getBoolConfig($carrier, 'pickup/active');

        return ! $this->cartRules->hidesDeliveryOptions($this->quote->getAllItems()) && $pickupEnabled;
    }

    /**
     * In the checkout Magento sends a first (fake) request where the standard country is ALWAYS US (regardless
     * of other settings). We can detect this because only the country and postcode (with value NULL) are posted,
     * while during the checkout process (when the user is typing) the other fields (eg city) will be posted as well.
     *
     * @param array $forAddress
     * @return bool
     */
    private function isFakeRequest(array $forAddress): bool
    {
        return 'US' === ($forAddress['countryId'] ?? null) && ! array_key_exists('city', $forAddress);
    }
}
