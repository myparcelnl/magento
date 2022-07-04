<?php

namespace MyParcelNL\Magento\Model\Quote;

use Magento\Checkout\Model\Cart;
use Magento\Checkout\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class Checkout
{
    private const PLATFORM             = 'myparcel';
    private const PACKAGE_TYPE_MAILBOX = 'mailbox';

    /**
     * @var \MyParcelNL\Magento\Helper\Checkout
     */
    private $helper;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quoteId;

    /**
     * @var PackageRepository
     */
    private $package;

    /**
     * @var \Magento\Eav\Model\Entity\Collection\AbstractCollection[]
     */
    private $cart;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $currency;

    /**
     * Checkout constructor.
     *
     * @param \Magento\Checkout\Model\Session            $session
     * @param \Magento\Checkout\Model\Cart               $cart
     * @param \MyParcelNL\Magento\Helper\Checkout        $helper
     * @param PackageRepository                          $package
     * @param \Magento\Store\Model\StoreManagerInterface $currency
     *
     */
    public function __construct(
        Session $session,
        Cart $cart,
        \MyParcelNL\Magento\Helper\Checkout $helper,
        PackageRepository $package,
        StoreManagerInterface $currency
    ) {
        $this->helper   = $helper;
        $this->quoteId  = $session->getQuoteId();
        $this->cart     = $cart->getQuote();
        $this->package  = $package;
        $this->currency = $currency;

        $this->package->setMailboxSettings();
        $this->package->setDigitalStampSettings();
    }

    /**
     * Get settings for MyParcel delivery options
     *
     * @param array $forAddress associative array holding the latest address from the client
     *
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getDeliveryOptions(array $forAddress = []): array
    {
        $this->helper->setBasePriceFromQuote($this->quoteId);
        $this->hideDeliveryOptionsForProduct();

        $data = [
            'methods'    => [$this->helper->getParentMethodNameFromQuote($this->quoteId, $forAddress)],
            'config'     => array_merge(
                $this->getGeneralData(),
                $this->getPackageType(),
                $this->getDeliveryData()
            ),
            'strings'    => $this->getDeliveryOptionsStrings(),
            'forAddress' => $forAddress,
        ];

        return [
            'root' => [
                'version' => (string) $this->helper->getVersion(),
                'data'    => (array) $data,
            ],
        ];
    }

    /**
     * Get general data
     *
     * @return array)
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getGeneralData()
    {
        return [
            'allowRetry'                 => true,
            'platform'                   => self::PLATFORM,
            'carriers'                   => $this->getActiveCarriers(),
            'currency'                   => $this->currency->getStore()->getCurrentCurrency()->getCode(),
            'pickupLocationsDefaultView' => $this->helper->getCarrierConfig('shipping_methods/pickup_locations_view', Data::XML_PATH_GENERAL)
        ];
    }

    /**
     * Get general data
     *
     * @return array
     */
    private function getPackageType(): array
    {
        $activeCarriers = $this->getActiveCarriers();

        $packageType = [];
        foreach ($activeCarriers as $carrier) {
            $packageType = [
                'packageType' => $this->checkPackageType($carrier, null),
            ];
        }

        return $packageType;
    }

    /**
     * Get delivery data
     *
     * @return array
     */
    private function getDeliveryData(): array
    {
        $myParcelConfig = [];
        $activeCarriers = $this->getActiveCarriers();
        $carrierPaths   = Data::CARRIERS_XML_PATH_MAP;

        foreach ($activeCarriers as $carrier) {
            $carrierPath = $carrierPaths[$carrier];

            try {
                $consignment = ConsignmentFactory::createByCarrierName($carrier);
                $consignment->setPackageType(AbstractConsignment::PACKAGE_TYPE_PACKAGE);
            } catch (\Throwable $ex) {
                $this->helper->log(sprintf('getDeliveryData: Could not create default consignment for %s', $carrier));
                continue;
            }

            $canHaveDigitalStamp  = $consignment->canHaveDeliveryType(AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME);
            $canHaveMailbox       = $consignment->canHaveDeliveryType(AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME);
            $canHaveSameDay       = $consignment->canHaveExtraOption(AbstractConsignment::SHIPMENT_OPTION_SAME_DAY_DELIVERY);
            $canHaveMonday        = $consignment->canHaveExtraOption(AbstractConsignment::EXTRA_OPTION_DELIVERY_MONDAY);
            $canHaveMorning       = $consignment->canHaveDeliveryType(AbstractConsignment::DELIVERY_TYPE_MORNING_NAME);
            $canHaveEvening       = $consignment->canHaveDeliveryType(AbstractConsignment::DELIVERY_TYPE_EVENING_NAME);
            $canHaveSignature     = $consignment->canHaveShipmentOption(AbstractConsignment::SHIPMENT_OPTION_SIGNATURE);
            $canHaveOnlyRecipient = $consignment->canHaveShipmentOption(AbstractConsignment::SHIPMENT_OPTION_ONLY_RECIPIENT);
            $canHaveAgeCheck      = $consignment->canHaveShipmentOption(AbstractConsignment::SHIPMENT_OPTION_AGE_CHECK);
            $canHavePickup        = $consignment->canHaveDeliveryType(AbstractConsignment::DELIVERY_TYPE_PICKUP_NAME);

            $basePrice        = $this->helper->getBasePrice();
            $morningFee       = $canHaveMorning ? $this->helper->getMethodPrice($carrierPath, 'morning/fee') : 0;
            $eveningFee       = $canHaveEvening ? $this->helper->getMethodPrice($carrierPath, 'evening/fee') : 0;
            $sameDayFee       = $canHaveSameDay ? (int) $this->helper->getCarrierConfig('delivery/same_day_delivery_fee', $carrierPath) : 0;
            $signatureFee     = $canHaveSignature ? $this->helper->getMethodPrice($carrierPath, 'delivery/signature_fee', false) : 0;
            $onlyRecipientFee = $canHaveOnlyRecipient ? $this->helper->getMethodPrice($carrierPath, 'delivery/only_recipient_fee', false) : 0;
            $isAgeCheckActive = $canHaveAgeCheck && $this->isAgeCheckActive($carrierPath);

            $myParcelConfig['carrierSettings'][$carrier] = [
                'allowDeliveryOptions'  => ! $this->package->deliveryOptionsDisabled && $this->helper->getBoolConfig($carrierPath, 'delivery/active'),
                'allowSignature'        => $canHaveSignature && $this->helper->getBoolConfig($carrierPath, 'delivery/signature_active'),
                'allowOnlyRecipient'    => $canHaveOnlyRecipient && $this->helper->getBoolConfig($carrierPath, 'delivery/only_recipient_active'),
                'allowMorningDelivery'  => ! $isAgeCheckActive && $canHaveMorning && $this->helper->getBoolConfig($carrierPath, 'morning/active'),
                'allowEveningDelivery'  => ! $isAgeCheckActive && $canHaveEvening && $this->helper->getBoolConfig($carrierPath, 'evening/active'),
                'allowPickupLocations'  => $canHavePickup && $this->isPickupAllowed($carrierPath),
                'allowShowDeliveryDate' => $this->helper->getBoolConfig($carrierPath, 'general/allow_show_delivery_date'),
                'allowMondayDelivery'   => $canHaveMonday ? $this->helper->getIntegerConfig($carrierPath, 'general/monday_delivery_active') : 0,
                'allowSameDayDelivery'  => $canHaveSameDay && $this->helper->getBoolConfig($carrierPath, 'delivery/same_day_active'),

                'cutoffTime'            => $this->helper->getTimeConfig($carrierPath, 'general/cutoff_time'),
                'cutoffTimeSameDay'     => $canHaveSameDay ? $this->helper->getTimeConfig($carrierPath, 'delivery/cutoff_time_same_day') : 0,
                'saturdayCutoffTime'    => $canHaveMonday ? $this->helper->getTimeConfig($carrierPath, 'general/saturday_cutoff_time') : 0,
                'deliveryDaysWindow'    => $this->helper->getIntegerConfig($carrierPath, 'general/deliverydays_window'),
                'dropOffDays'           => $this->helper->getArrayConfig($carrierPath, 'general/dropoff_days'),
                'dropOffDelay'          => $this->getDropOffDelay($carrierPath, 'general/dropoff_delay'),

                'priceSignature'                       => $signatureFee,
                'priceOnlyRecipient'                   => $onlyRecipientFee,
                'priceStandardDelivery'                => $basePrice,
                'priceMorningDelivery'                 => $morningFee,
                'priceEveningDelivery'                 => $eveningFee,
                'priceSameDayDelivery'                 => $sameDayFee,
                'priceSameDayDeliveryAndOnlyRecipient' => $sameDayFee + $onlyRecipientFee,

                'priceMorningSignature'          => ($morningFee + $signatureFee),
                'priceEveningSignature'          => ($eveningFee + $signatureFee),
                'priceSignatureAndOnlyRecipient' => ($basePrice + $signatureFee + $onlyRecipientFee),

                'pricePickup'                  => $canHavePickup ? $this->helper->getMethodPrice($carrierPath, 'pickup/fee') : 0,
                'pricePackageTypeMailbox'      => $canHaveMailbox ? $this->helper->getMethodPrice($carrierPath, 'mailbox/fee', false) : 0,
                'pricePackageTypeDigitalStamp' => $canHaveDigitalStamp ? $this->helper->getMethodPrice($carrierPath, 'digital_stamp/fee', false) : 0,
            ];
        }

        return $myParcelConfig;
    }

    /**
     * Get a list of the shipping methods.
     *
     * @return string
     */
    private function getDeliveryMethods(): string
    {
        return $this->helper->getArrayConfig(Data::XML_PATH_GENERAL, 'shipping_methods/methods');
    }

    /**
     * Get the array of enabled carriers by checking if they have either delivery or pickup enabled.
     *
     * @return array
     */
    public function getActiveCarriers(): array
    {
        $carriers = [];

        foreach (Data::CARRIERS_XML_PATH_MAP as $carrier => $path) {
            if ($this->helper->getBoolConfig($path, 'delivery/active') ||
                $this->helper->getBoolConfig($path, 'pickup/active')
            ) {
                $carriers[] = $carrier;
            }
        }

        return $carriers;
    }

    /**
     * Get delivery options strings
     *
     * @return array
     */
    private function getDeliveryOptionsStrings(): array
    {
        return [
            'deliveryTitle'             => $this->helper->getGeneralConfig('delivery_titles/delivery_title'),
            'deliveryStandardTitle'     => $this->helper->getGeneralConfig('delivery_titles/standard_delivery_title'),
            'deliveryMorningTitle'      => $this->helper->getGeneralConfig('delivery_titles/morning_title'),
            'deliveryEveningTitle'      => $this->helper->getGeneralConfig('delivery_titles/evening_title'),
            'packageTypeMailbox'        => $this->helper->getGeneralConfig('delivery_titles/mailbox_title'),
            'packageTypeDigitalStamp'   => $this->helper->getGeneralConfig('delivery_titles/digital_stamp_title'),
            'pickupTitle'               => $this->helper->getGeneralConfig('delivery_titles/pickup_title'),
            'pickupLocationsListButton' => $this->helper->getGeneralConfig('delivery_titles/pickup_list_button_title'),
            'pickupLocationsMapButton'  => $this->helper->getGeneralConfig('delivery_titles/pickup_map_button_title'),
            'signatureTitle'            => $this->helper->getGeneralConfig('delivery_titles/signature_title'),
            'onlyRecipientTitle'        => $this->helper->getGeneralConfig('delivery_titles/only_recipient_title'),
            'saturdayDeliveryTitle'     => $this->helper->getGeneralConfig('delivery_titles/saturday_title'),

            'wrongPostalCodeCity' => __('Postcode/city combination unknown'),
            'addressNotFound'     => __('Address details are not entered'),
            'closed'              => __('Closed'),
            'retry'               => __('Again'),
            'pickUpFrom'          => __('Pick up from'),
            'openingHours'        => __('Opening hours'),

            'cityText'       => __('City'),
            'postalCodeText' => __('Postcode'),
            'numberText'     => __('House number'),
            'city'           => __('City'),
            'postcode'       => __('Postcode'),
            'houseNumber'    => __('House number'),
        ];
    }

    /**
     * @param string      $carrier
     * @param string|null $country
     *
     * @return string
     */
    public function checkPackageType(string $carrier, ?string $country): string
    {
        try {
            $consignment         = ConsignmentFactory::createByCarrierName($carrier);
        } catch (\Throwable $e) {
            $this->helper->log(sprintf('checkPackageType: Could not create default consignment for %s', $carrier));

            return AbstractConsignment::DEFAULT_PACKAGE_TYPE_NAME;
        }

        $carrierPath         = Data::CARRIERS_XML_PATH_MAP[$carrier];
        $products            = $this->cart->getAllItems();
        $country             = $country ?? $this->cart->getShippingAddress()->getCountryId();
        $canHaveDigitalStamp = $consignment->canHavePackageType(AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME);
        $canHaveMailbox      = $consignment->canHavePackageType(AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME);

        $this->package->setCurrentCountry($country);
        $this->package->setDigitalStampActive($canHaveDigitalStamp && $this->helper->getBoolConfig($carrierPath, 'digital_stamp/active'));
        $this->package->setMailboxActive($canHaveMailbox && $this->helper->getBoolConfig($carrierPath, 'mailbox/active'));
        $this->package->setWeightFromQuoteProducts($products);

        return $this->package->selectPackageType($products, $carrierPath);
    }

    /**
     * @param string $carrierPath
     *
     * @return bool
     */
    public function isAgeCheckActive(string $carrierPath): bool
    {
        $products = $this->cart->getAllItems();

        return $this->package->getAgeCheck($products, $carrierPath);
    }

    /**
     * @param string $carrierPath
     * @param string $key
     *
     * @return int
     */
    public function getDropOffDelay(string $carrierPath, string $key): int
    {
        $products     = $this->cart->getAllItems();
        $productDelay = $this->package->getProductDropOffDelay($products);

        if (! $productDelay) {
            $productDelay = $this->helper->getIntegerConfig($carrierPath, $key);
        }

        return (int) $productDelay;
    }

    /**
     * @return $this
     */
    public function hideDeliveryOptionsForProduct()
    {
        $products = $this->cart->getAllItems();
        $this->package->productWithoutDeliveryOptions($products);

        return $this;
    }

    /**
     * @param  string $carrier
     *
     * @return bool
     */
    private function isPickupAllowed(string $carrier): bool
    {
        $isMailboxPackage     = self::PACKAGE_TYPE_MAILBOX === $this->getPackageType()['packageType'];
        $pickupEnabled        = $this->helper->getBoolConfig($carrier, 'pickup/active');
        $showPickupForMailbox = $this->helper->getBoolConfig($carrier, 'mailbox/pickup_mailbox');
        $showPickup           = ! $isMailboxPackage || $showPickupForMailbox;

        return ! $this->package->deliveryOptionsDisabled && $pickupEnabled && $showPickup;
    }
}
