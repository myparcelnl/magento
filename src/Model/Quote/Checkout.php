<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\NeedsQuoteProps;
use MyParcelNL\Magento\Service\Tax;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\Services\CountryCodes;
use Throwable;

class Checkout
{
    use NeedsQuoteProps;

    public const MAGENTO_CARRIER_CODE_FREE_SHIPPING = 'freeshipping';

    private Tax                   $tax;
    private Config                $config;
    private DeliveryCosts         $deliveryCosts;
    private PackageRepository     $package;
    private Quote                 $quote;
    private StoreManagerInterface $currency;

    /**
     * Checkout constructor.
     *
     * @param Tax                   $tax
     * @param Config                $config
     * @param DeliveryCosts         $deliveryCosts
     * @param PackageRepository     $package
     * @param StoreManagerInterface $currency
     */
    public function __construct(
        Tax                   $tax,
        Config                $config,
        DeliveryCosts         $deliveryCosts,
        PackageRepository     $package, // TODO DEPRECATE / IMPROVE
        StoreManagerInterface $currency
    )
    {
        $this->tax           = $tax;
        $this->config        = $config;
        $this->deliveryCosts = $deliveryCosts;
        $this->package       = $package;
        $this->currency      = $currency;
        $this->quote         = $this->getQuoteFromCurrentSession();
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
            $country = $this->quote->getShippingAddress()->getCountryId() ?? $this->config->getConfigValue('general/country/default') ?? AbstractConsignment::CC_NL;
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
        $carrierPath = !empty($activeCarriers) ? Config::CARRIERS_XML_PATH_MAP[$activeCarriers[0]] : Config::XML_PATH_POSTNL_SETTINGS;

        return [
            'platform'                          => Config::PLATFORM,
            'currency'                          => $this->currency->getStore()->getCurrentCurrency()->getCode(),
            'showDeliveryDate'                  => $this->config->getBoolConfig(Config::XML_PATH_GENERAL, 'date_settings/allow_show_delivery_date'),
            'deliveryDaysWindow'                => $this->config->getIntegerConfig(Config::XML_PATH_GENERAL, 'date_settings/deliverydays_window'),
            'dropOffDelay'                      => $this->getDropOffDelay(Config::XML_PATH_GENERAL, 'date_settings/dropoff_delay'),
            'pickupLocationsDefaultView'        => $this->config->getConfigValue(Config::XML_PATH_GENERAL . 'shipping_methods/pickup_locations_view'),
            'allowPickupLocationsViewSelection' => true,
            'showPriceSurcharge'                => $this->config->getConfigValue(Config::XML_PATH_GENERAL . 'shipping_methods/delivery_options_prices') === PriceDeliveryOptionsView::SURCHARGE,
            'excludeParcelLockers'              => $this->isExcludeParcelLockersActive($carrierPath),
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
        $packageType    = AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;
        $activeCarriers = $this->getActiveCarriers();

        foreach ($activeCarriers as $carrier) {
            $tentativePackageType = $this->checkPackageType($carrier, $country);

            switch ($tentativePackageType) {
                case AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME:
                    return AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME;
                case AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME:
                    $packageType = AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME;
                    break;
                case AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL_NAME:
                    return AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL_NAME;
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

        foreach ($activeCarriers as $carrierName) {
            $carrierPath = $carrierPaths[$carrierName];
            $basePrice   = $this->deliveryCosts->getBasePriceForClient($quote, $carrierName, $packageType, $country);

            try {
                $consignment = ConsignmentFactory::createByCarrierName($carrierName);
                $consignment->setPackageType(AbstractConsignment::PACKAGE_TYPE_PACKAGE);
            } catch (Throwable $ex) {
                Logger::info(sprintf('getDeliveryData: Could not create default consignment for %s', $carrierName));
                continue;
            }

            $canHaveSameDay       = $consignment->canHaveExtraOption(AbstractConsignment::SHIPMENT_OPTION_SAME_DAY_DELIVERY);
            $canHaveMonday        = $consignment->canHaveExtraOption(AbstractConsignment::EXTRA_OPTION_DELIVERY_MONDAY);
            $canHaveMorning       = $consignment->canHaveDeliveryType(AbstractConsignment::DELIVERY_TYPE_MORNING_NAME);
            $canHaveEvening       = $consignment->canHaveDeliveryType(AbstractConsignment::DELIVERY_TYPE_EVENING_NAME);
            $canHaveExpress       = $consignment->canHaveDeliveryType(AbstractConsignment::DELIVERY_TYPE_EXPRESS_NAME);
            $canHavePickup        = $consignment->canHaveDeliveryType(AbstractConsignment::DELIVERY_TYPE_PICKUP_NAME);
            $canHaveSignature     = $consignment->canHaveShipmentOption(AbstractConsignment::SHIPMENT_OPTION_SIGNATURE);
            $canHaveCollect       = $consignment->canHaveShipmentOption(AbstractConsignment::SHIPMENT_OPTION_COLLECT);
            $canHaveReceiptCode   = $consignment->canHaveShipmentOption(AbstractConsignment::SHIPMENT_OPTION_RECEIPT_CODE);
            $canHaveOnlyRecipient = $consignment->canHaveShipmentOption(AbstractConsignment::SHIPMENT_OPTION_ONLY_RECIPIENT);
            $canHaveAgeCheck      = $consignment->canHaveShipmentOption(AbstractConsignment::SHIPMENT_OPTION_AGE_CHECK);

            $addBasePrice     = ($showTotalPrice) ? $basePrice : 0;
            $mondayFee        = $canHaveMonday ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/monday_fee'), $quote) + $addBasePrice : 0;
            $morningFee       = $canHaveMorning ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'morning/fee'), $quote) + $addBasePrice : 0;
            $eveningFee       = $canHaveEvening ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'evening/fee'), $quote) + $addBasePrice : 0;
            $sameDayFee       = $canHaveSameDay ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/same_day_delivery_fee'), $quote) + $addBasePrice : 0;
            $signatureFee     = $canHaveSignature ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/signature_fee'), $quote) : 0;
            $collectFee       = $canHaveCollect ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/collect_fee'), $quote) : 0;
            $receiptCodeFee   = $canHaveReceiptCode ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/receipt_code_fee'), $quote) : 0;
            $onlyRecipientFee = $canHaveOnlyRecipient ? $this->tax->shippingPrice($this->config->getFloatConfig($carrierPath, 'delivery/only_recipient_fee'), $quote) : 0;
            $isAgeCheckActive = $canHaveAgeCheck && $this->isAgeCheckActive($carrierPath);

            $allowPickup           = $this->config->getBoolConfig($carrierPath, 'pickup/active');
            $allowStandardDelivery = $this->config->getBoolConfig($carrierPath, 'delivery/active');
            $allowMorningDelivery  = ! $isAgeCheckActive && $canHaveMorning && $this->config->getBoolConfig($carrierPath, 'morning/active');
            $allowEveningDelivery  = ! $isAgeCheckActive && $canHaveEvening && $this->config->getBoolConfig($carrierPath, 'evening/active');
            $allowExpressDelivery  = $canHaveExpress && $this->config->getBoolConfig($carrierPath, 'express/active');
            $allowDeliveryOptions  = ! $this->package->deliveryOptionsDisabled
                                     && ($allowPickup || $allowStandardDelivery || $allowMorningDelivery || $allowEveningDelivery);

            if ($allowDeliveryOptions && $packageType === AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME) {
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
            'deliveryTitle'           => $this->config->getGeneralConfig('delivery_titles/delivery_title') ?: __('delivery_title'),
            'deliveryStandardTitle'   => $this->config->getGeneralConfig('delivery_titles/standard_delivery_title') ?: __('standard_title'),
            'deliveryMorningTitle'    => $this->config->getGeneralConfig('delivery_titles/morning_title') ?: __('morning_title'),
            'deliveryEveningTitle'    => $this->config->getGeneralConfig('delivery_titles/evening_title') ?: __('evening_title'),
            'deliveryPickupTitle'     => $this->config->getGeneralConfig('delivery_titles/pickup_title') ?: __('pickup_title'),
            'pickupTitle'             => $this->config->getGeneralConfig('delivery_titles/pickup_title') ?: __('pickup_title'),
            'deliverySameDayTitle'    => $this->config->getGeneralConfig('delivery_titles/same_day_title') ?: __('same_day_title'),
            'hideSenderTitle'         => $this->config->getGeneralConfig('delivery_titles/hide_sender_title') ?: __('hide_sender_title'),
            'list'                    => $this->config->getGeneralConfig('delivery_titles/pickup_list_button_title') ?: __('list_title'),
            'map'                     => $this->config->getGeneralConfig('delivery_titles/pickup_map_button_title') ?: __('map_title'),
            'packageTypeMailbox'      => $this->config->getGeneralConfig('delivery_titles/mailbox_title') ?: __('mailbox_title'),
            'packageTypeDigitalStamp' => $this->config->getGeneralConfig('delivery_titles/digital_stamp_title') ?: __('digital_stamp_title'),
            'packageTypePackageSmall' => $this->config->getGeneralConfig('delivery_titles/package_small_title') ?: __('packet_title'),
            'signatureTitle'          => $this->config->getGeneralConfig('delivery_titles/signature_title') ?: __('signature_title'),
            'onlyRecipientTitle'      => $this->config->getGeneralConfig('delivery_titles/only_recipient_title') ?: __('only_recipient_title'),
            'saturdayDeliveryTitle'   => $this->config->getGeneralConfig('delivery_titles/saturday_title') ?: __('saturday_delivery_title'),

            'wrongPostalCodeCity' => __('Postcode/city combination unknown'),
            'addressNotFound'     => __('Address details are not entered'),
            'closed'              => __('Closed'),
            'discount'            => __('Discount'),
            'ecoFriendly'         => __('Most sustainable'),
            'free'                => __('Free'),
            'from'                => __('From'),
            'retry'               => __('Again'),
            'parcelLocker'        => __('Parcel locker'),
            'pickUpFrom'          => __('Pick up from'),
            'openingHours'        => __('Opening hours'),
            'showMoreHours'       => __('Show more opening hours'),
            'showMoreLocations'   => __('Show more locations'),

            'error3212' => __('{field} is required.'),
            'error3501' => __('Address not found.'),
            'error3505' => __('Postal code is invalid for the current country.'),

            'cityText'    => __('City'),
            'city'        => __('City'),
            'cc'          => __('Country'),
            'houseNumber' => __('House number'),
            'numberText'  => __('House number'),
            'postalCode'  => __('Postal code'),
            'street'      => __('Street'),
        ];
    }

    /**
     * @param string $carrier
     * @param string $country
     *
     * @return string
     */
    public function checkPackageType(string $carrier, string $country): string
    {
        try {
            $consignment = ConsignmentFactory::createByCarrierName($carrier);
        } catch (Throwable $e) {
            Logger::critical(sprintf('checkPackageType: Could not create default consignment for %s', $carrier));

            return AbstractConsignment::DEFAULT_PACKAGE_TYPE_NAME;
        }

        $carrierPath         = Config::CARRIERS_XML_PATH_MAP[$carrier];
        $products            = $this->quote->getAllItems();
        $canHaveDigitalStamp = $consignment->canHavePackageType(AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME);
        $canHaveMailbox      = $consignment->canHavePackageType(AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME);
        $canHavePackageSmall = $consignment->canHavePackageType(AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL_NAME);

        $this->package->setMailboxSettings($carrierPath);
        $this->package->setDigitalStampSettings($carrierPath);
        $this->package->setPackageSmallSettings($carrierPath);

        if ($canHaveMailbox) {
            if (AbstractConsignment::CC_NL === $country) {
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

        return $this->package->selectPackageType($products, $carrierPath);
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
        $pickupEnabled = AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME === $this->getPackageType($country)
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
