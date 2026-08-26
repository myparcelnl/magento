<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\NeedsQuoteProps;
use MyParcelNL\Magento\Service\Tax;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;
use MyParcelNL\Sdk\Services\CountryCodes;

class Checkout
{
    use NeedsQuoteProps;

    public const MAGENTO_CARRIER_CODE_FREE_SHIPPING = 'freeshipping';

    private Tax                   $tax;
    private Config                $config;
    private DeliveryCosts         $deliveryCosts;
    private PackageRepository     $package;
    private Quote                 $quote;
    private StoreManagerInterface $storeManager;
    private CapabilitiesRepository $capabilitiesRepository;

    /**
     * Keyed by country and package type, '' for the package-type-agnostic lookup. A checkout
     * resolves one package type before it asks anything else, so this holds two entries at most.
     *
     * @var array<string,CapabilitySet>
     */
    private array $capabilities = [];

    /**
     * Checkout constructor.
     *
     * @param Tax                    $tax
     * @param Config                 $config
     * @param DeliveryCosts          $deliveryCosts
     * @param PackageRepository      $package
     * @param StoreManagerInterface  $storeManager
     * @param CapabilitiesRepository $capabilitiesRepository
     */
    public function __construct(
        Tax                   $tax,
        Config                $config,
        DeliveryCosts         $deliveryCosts,
        PackageRepository     $package, // TODO DEPRECATE / IMPROVE
        StoreManagerInterface $storeManager,
        CapabilitiesRepository $capabilitiesRepository
    )
    {
        $this->tax                    = $tax;
        $this->config                 = $config;
        $this->deliveryCosts          = $deliveryCosts;
        $this->package                = $package;
        $this->storeManager           = $storeManager;
        $this->capabilitiesRepository = $capabilitiesRepository;
        $this->quote         = $this->getQuoteFromCurrentSession();

        // Must happen before any setMailboxSettings() call, which reads config.
        if (null !== $this->quote) {
            $this->package->setStoreId((int) $this->quote->getStoreId());
        }
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
        $this->hideDeliveryOptionsForProduct();

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
        $carrierPath    = ! empty($activeCarriers) ? Config::CARRIERS_XML_PATH_MAP[$activeCarriers[0]] : Config::XML_PATH_POSTNL_SETTINGS;
        $deliveryDaysWindow = $this->config->getIntegerConfig(Config::XML_PATH_GENERAL, 'date_settings/deliverydays_window');

        return [
            'platform'                          => Config::PLATFORM,
            'currency'                          => $this->storeManager->getStore()->getCurrentCurrency()->getCode(),
            'proxyCapabilities'                 => $this->storeManager->getStore()->getBaseUrl() . 'myparcel/proxy/core/shipments/capabilities',
            'showDeliveryDate'                  => $deliveryDaysWindow > 0,
            'deliveryDaysWindow'                => $deliveryDaysWindow,
            'dropOffDelay'                      => $this->getDropOffDelay(Config::XML_PATH_GENERAL, 'date_settings/dropoff_delay'),
            'pickupLocationsDefaultView'        => $this->config->getConfigValue(Config::XML_PATH_GENERAL . 'shipping_methods/pickup_locations_view'),
            'allowPickupLocationsViewSelection' => $this->config->getBoolConfig(Config::XML_PATH_GENERAL, 'shipping_methods/pickup_locations_view_change_allowed'),
            'showPriceSurcharge'                => $this->config->getConfigValue(Config::XML_PATH_GENERAL . 'shipping_methods/delivery_options_prices') === PriceDeliveryOptionsView::SURCHARGE,
            'excludeParcelLockers'              => $this->isExcludeParcelLockersActive($carrierPath),
            'compactView'                       => $this->config->getBoolConfig(Config::XML_PATH_GENERAL, 'shipping_methods/compact_view'),
            'popUpMap'                          => $this->config->getBoolConfig(Config::XML_PATH_GENERAL, 'shipping_methods/pop_up_map'),
        ];
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
        $carrierPaths   = Config::CARRIERS_XML_PATH_MAP;
        $showTotalPrice = $this->config->getConfigValue(Config::XML_PATH_GENERAL . 'shipping_methods/delivery_options_prices') === PriceDeliveryOptionsView::TOTAL;

        $quote = $this->quote;
        $caps  = $this->getCapabilities($country, $packageType);

        foreach ($activeCarriers as $carrierName) {
            $carrierPath = $carrierPaths[$carrierName];
            $basePrice   = $this->deliveryCosts->getBasePriceForClient($quote, $carrierName, $packageType, $country);

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
            $canHaveAgeCheck         = $caps->hasOption($carrierName, $packageType, ShipmentOption::AGE_CHECK);
            // Monday delivery is not a capability the API reports, so configuration alone decides.
            // Offering it and letting the API refuse beats hiding a feature the merchant pays for.
            $canHaveMonday           = true;

            $addBasePrice        = ($showTotalPrice) ? $basePrice : 0;
            $mondayFee           = $canHaveMonday ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/monday_fee'), $quote) + $addBasePrice : 0;
            $morningFee          = $canHaveMorning ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'morning/fee'), $quote) + $addBasePrice : 0;
            $eveningFee          = $canHaveEvening ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'evening/fee'), $quote) + $addBasePrice : 0;
            $sameDayFee          = $canHaveSameDay ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/same_day_delivery_fee'), $quote) + $addBasePrice : 0;
            $signatureFee        = $canHaveSignature ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/signature_fee'), $quote) : 0;
            $collectFee          = $canHaveCollect ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/collect_fee'), $quote) : 0;
            $receiptCodeFee      = $canHaveReceiptCode ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/receipt_code_fee'), $quote) : 0;
            $priorityDeliveryFee = $canHavePriorityDelivery ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'mailbox/priority_delivery_fee'), $quote) : 0;
            $onlyRecipientFee    = $canHaveOnlyRecipient ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/only_recipient_fee'), $quote) : 0;
            $isAgeCheckActive    = $canHaveAgeCheck && $this->isAgeCheckActive($carrierPath);

            $allowPickup           = $this->config->getBoolConfig($carrierPath, 'pickup/active');
            $allowStandardDelivery = $this->config->getBoolConfig($carrierPath, 'delivery/active');
            $allowMorningDelivery  = ! $isAgeCheckActive && $canHaveMorning && $this->config->getBoolConfig($carrierPath, 'morning/active');
            $allowEveningDelivery  = ! $isAgeCheckActive && $canHaveEvening && $this->config->getBoolConfig($carrierPath, 'evening/active');
            $allowExpressDelivery  = $canHaveExpress && $this->config->getBoolConfig($carrierPath, 'express/active');
            $allowDeliveryOptions  = ! $this->package->deliveryOptionsDisabled
                                     && ($allowPickup || $allowStandardDelivery || $allowMorningDelivery || $allowEveningDelivery);

            if ($allowDeliveryOptions && $packageType === PackageType::MAILBOX_NAME) {
                $this->package->setMailboxSettings($carrierPath);
                $allowDeliveryOptions = $this->config->getBoolConfig($carrierPath, 'mailbox/active')
                                        && $this->package->getMaxMailboxWeight() >= $this->package->getWeight();
            }

            $myParcelConfig['carrierSettings'][$carrierName] = [
                'allowDeliveryOptions'  => $allowDeliveryOptions,
                'allowStandardDelivery' => $allowStandardDelivery,
                'allowSignature'        => $canHaveSignature && $this->config->getBoolConfig($carrierPath, 'delivery/signature_active'),
                'allowCollect'          => $canHaveCollect && $this->config->getBoolConfig($carrierPath, 'delivery/collect_active'),
                'allowReceiptCode'      => $canHaveReceiptCode && $this->config->getBoolConfig($carrierPath, 'delivery/receipt_code_active'),
                'allowOnlyRecipient'    => $canHaveOnlyRecipient && $this->config->getBoolConfig($carrierPath, 'delivery/only_recipient_active'),
                'allowPriorityDelivery' => $canHavePriorityDelivery && $this->package->getPriorityDelivery($quote->getAllItems(), $carrierPath),
                'allowMorningDelivery'  => $allowMorningDelivery,
                'allowEveningDelivery'  => $allowEveningDelivery,
                'allowPickupLocations'  => $canHavePickup && $this->isPickupAllowed($carrierPath, $country),
                'allowMondayDelivery'   => $canHaveMonday && $this->config->getBoolConfig($carrierPath, 'delivery/monday_active'),
                'allowSameDayDelivery'  => $canHaveSameDay && $this->config->getBoolConfig($carrierPath, 'delivery/same_day_delivery_active'),
                'allowExpressDelivery'  => $allowExpressDelivery,

                'dropOffDays' => $this->getDropOffDays($carrierPath),

                'priceSignature'        => $signatureFee,
                'priceCollect'          => $collectFee,
                'priceReceiptCode'      => $receiptCodeFee,
                'priceOnlyRecipient'    => $onlyRecipientFee,
                'pricePriorityDelivery' => $priorityDeliveryFee,
                'priceStandardDelivery' => $addBasePrice,
                'priceMondayDelivery'   => $mondayFee,
                'priceMorningDelivery'  => $morningFee,
                'priceEveningDelivery'  => $eveningFee,
                'priceSameDayDelivery'  => $sameDayFee,
                'pricePickup'           => max(0, $canHavePickup ? $this->config->getFloatConfig($carrierPath, 'pickup/fee') + $basePrice : 0),
                // because of how the delivery options work, we need to put the correctly calculated price in separate keys:
                'pricePackageTypeMailbox'      => $basePrice,
                'pricePackageTypeDigitalStamp' => $basePrice,
                'pricePackageTypePackageSmall' => $basePrice,
                // if you want separate package type prices, get them with this: $this->deliveryCosts->getBasePrice($this->quote, $carrierName, $packageType, $country);
            ];
        }

        return $myParcelConfig;
    }

    /**
     * Capabilities for one shipment shape. Pass null for the shape-agnostic question, which is the
     * only one that can say which package types a carrier has.
     *
     * A shape-agnostic response is a superset: it groups every package type of a carrier into one
     * result carrying the union of their options, so anything that varies per package type must be
     * asked with the package type set. See DR-18.
     */
    private function getCapabilities(string $country, ?string $packageType = null): CapabilitySet
    {
        $key = $country . '|' . (string) $packageType;

        if (isset($this->capabilities[$key])) {
            return $this->capabilities[$key];
        }

        $request = CapabilitiesRequest::forCountry($country);

        if (null !== $packageType) {
            $v2 = PackageType::toV2Name($packageType);

            if (null === $v2) {
                return $this->capabilities[$key] = CapabilitySet::permissive();
            }

            $request = $request->withPackageType($v2);
        }

        return $this->capabilities[$key] = $this->capabilitiesRepository->forStore(
            (int) $this->quote->getStoreId(),
            $request
        );
    }

    /**
     * Get the array of enabled carriers by checking if they have either delivery or pickup enabled.
     *
     * @return array
     */
    public function getActiveCarriers(): array
    {
        $carriers = [];
        foreach (Config::CARRIERS_XML_PATH_MAP as $carrier => $path) {
            if ($this->config->getBoolConfig($path, 'delivery/active') ||
                $this->config->getBoolConfig($path, 'pickup/active')
            ) {
                $carriers[] = $carrier;
            }
        }

        return $carriers;
    }

    private function getDropOffDays(string $carrierPath): array
    {
        $dropOffDays = [];
        for ($weekday = 0; $weekday < 7; $weekday++) {
            $cutoffTimeSameDay = $this->config->getTimeConfig($carrierPath, "drop_off_days/cutoff_time_same_day_$weekday");
            $sameDayTimeEntry  = $cutoffTimeSameDay ? ['cutoffTimeSameDay' => $cutoffTimeSameDay] : [];
            if ($this->config->getBoolConfig($carrierPath, "drop_off_days/day_{$weekday}_active")) {
                $dropOffDays[] = (object) array_merge(
                    [
                        'weekday'    => $weekday,
                        'cutoffTime' => $this->config->getTimeConfig($carrierPath, "drop_off_days/cutoff_time_$weekday"),
                    ],
                    $sameDayTimeEntry
                );
            }
        }

        return $dropOffDays;
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
            'mondayDeliveryTitle'   => $this->config->getGeneralConfig('delivery_titles/monday_delivery_title') ?: __('Monday delivery'),
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
        // The package-type-agnostic question: this method is what decides the package type, so
        // there is none to narrow by yet.
        $caps = $this->getCapabilities($country);

        $carrierPath         = Config::CARRIERS_XML_PATH_MAP[$carrierName];
        $products            = $this->quote->getAllItems();
        $canHaveDigitalStamp = $caps->hasPackageType($carrierName, PackageType::DIGITAL_STAMP_NAME);
        $canHaveMailbox      = $caps->hasPackageType($carrierName, PackageType::MAILBOX_NAME);
        $canHavePackageSmall = $caps->hasPackageType($carrierName, PackageType::PACKAGE_SMALL_NAME);

        $this->package->setMailboxSettings($carrierPath);
        $this->package->setDigitalStampSettings($carrierPath);
        $this->package->setPackageSmallSettings($carrierPath);

        if ($canHaveMailbox) {
            if (CountryCode::CC_NL === $country) {
                $this->package->setMailboxActive($this->config->getBoolConfig($carrierPath, 'mailbox/active'));
            } else {
                $this->package->setMailboxActive($this->config->getBoolConfig($carrierPath, 'mailbox/international_active'));
            }
        } else {
            $this->package->setMailboxActive(false);
        }

        $this->package->setCurrentCountry($country);
        $this->package->setDigitalStampActive($canHaveDigitalStamp && $this->config->getBoolConfig($carrierPath, 'digital_stamp/active'));
        $this->package->setPackageSmallActive($canHavePackageSmall && $this->config->getBoolConfig($carrierPath, 'package_small/active'));

        return $this->package->selectPackageType($products, $carrierName);
    }

    /**
     * @param string $carrierPath
     *
     * @return bool
     */
    public function isAgeCheckActive(string $carrierPath): bool
    {
        $products = $this->quote->getAllItems();

        return $this->package->getAgeCheck($products, $carrierPath);
    }

    /**
     * @param string $carrierPath
     *
     * @return bool
     */
    private function isExcludeParcelLockersActive(string $carrierPath): bool
    {
        $products = $this->quote->getAllItems();

        return $this->package->getExcludeParcelLockers($products, $carrierPath);
    }

    /**
     * @param string $carrierPath
     * @param string $key
     *
     * @return int
     */
    public function getDropOffDelay(string $carrierPath, string $key): int
    {
        $products     = $this->quote->getAllItems();
        $productDelay = (int) $this->package->getProductDropOffDelay($products);
        $configDelay  = $this->config->getIntegerConfig($carrierPath, $key);

        return max($productDelay, $configDelay);
    }

    /**
     * @return self
     */
    public function hideDeliveryOptionsForProduct(): self
    {
        $products = $this->quote->getAllItems();
        $this->package->productWithoutDeliveryOptions($products);

        return $this;
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

        return ! $this->package->deliveryOptionsDisabled && $pickupEnabled;
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
