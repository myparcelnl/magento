<?php

namespace MyParcelNL\Magento\Model\Quote;

use Magento\Checkout\Model\Cart;
use Magento\Checkout\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;

class Checkout
{
    private const PLATFORM = 'myparcel';

    /**
     * @var array
     */
    private $data = [];

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
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $carrier;

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

        $this->data = [
            'methods' => [$this->helper->getParentMethodNameFromQuote($this->quoteId, $forAddress)],
            'config'  => array_merge(
                $this->getGeneralData(),
                $this->getPackageType(),
                $this->getDeliveryData()
            ),
            'strings' => $this->getDeliveryOptionsStrings(),
        ];
        $this->data['forAddress'] = $forAddress;

        return [
            'root' => [
                'version' => (string) $this->helper->getVersion(),
                'data'    => (array) $this->data
            ]
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
                'packageType'                  => $this->checkPackageType($carrier, null),
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
        $carrierPath    = Data::CARRIERS_XML_PATH_MAP;

        foreach ($activeCarriers as $carrier) {
            $basePrice        = $this->helper->getBasePrice();
            $morningFee       = $this->helper->getMethodPrice($carrierPath[$carrier], 'morning/fee');
            $eveningFee       = $this->helper->getMethodPrice($carrierPath[$carrier], 'evening/fee');
            $signatureFee     = $this->helper->getMethodPrice($carrierPath[$carrier], 'delivery/signature_fee', false);
            $onlyRecipientFee = $this->helper->getMethodPrice($carrierPath[$carrier], 'delivery/only_recipient_fee', false);
            $isAgeCheckActive = $this->isAgeCheckActive($carrierPath[$carrier]);
            $mailboxPackage   = $this->getPackageType()['packageType'] === 'mailbox';
            $showPickup       = $this->helper->getBoolConfig($carrierPath[$carrier], 'mailbox/pickup_mailbox');

            $myParcelConfig['carrierSettings'][$carrier] = [
                'allowDeliveryOptions'  => $this->package->deliveryOptionsDisabled ? false : $this->helper->getBoolConfig($carrierPath[$carrier], 'delivery/active'),
                'allowSignature'        => $this->helper->getBoolConfig($carrierPath[$carrier], 'delivery/signature_active'),
                'allowOnlyRecipient'    => $this->helper->getBoolConfig($carrierPath[$carrier], 'delivery/only_recipient_active'),
                 'allowMorningDelivery' => $isAgeCheckActive ? false : $this->helper->getBoolConfig($carrierPath[$carrier], 'morning/active'),
                'allowEveningDelivery'  => $isAgeCheckActive ? false : $this->helper->getBoolConfig($carrierPath[$carrier], 'evening/active'),
                'allowPickupLocations'  => $this->package->deliveryOptionsDisabled || ($mailboxPackage && !$showPickup) ? false : $this->helper->getBoolConfig($carrierPath[$carrier], 'pickup/active'),
                'allowShowDeliveryDate' => $this->helper->getBoolConfig($carrierPath[$carrier], 'general/allow_show_delivery_date'),
                'allowMondayDelivery'   => $this->helper->getIntegerConfig($carrierPath[$carrier], 'general/monday_delivery_active'),

                'cutoffTime'            => $this->helper->getTimeConfig($carrierPath[$carrier], 'general/cutoff_time'),
                'saturdayCutoffTime'    => $this->helper->getTimeConfig($carrierPath[$carrier], 'general/saturday_cutoff_time'),
                'deliveryDaysWindow'    => $this->helper->getIntegerConfig($carrierPath[$carrier], 'general/deliverydays_window'),
                'dropOffDays'           => $this->helper->getArrayConfig($carrierPath[$carrier], 'general/dropoff_days'),
                'dropOffDelay'          => $this->getDropOffDelay($carrierPath[$carrier], 'general/dropoff_delay'),

                'priceSignature'        => $signatureFee,
                'priceOnlyRecipient'    => $onlyRecipientFee,
                'priceStandardDelivery' => $basePrice,
                'priceMorningDelivery'  => $morningFee,
                'priceEveningDelivery'  => $eveningFee,

                'priceMorningSignature'          => ($morningFee + $signatureFee),
                'priceEveningSignature'          => ($eveningFee + $signatureFee),
                'priceSignatureAndOnlyRecipient' => ($basePrice + $signatureFee + $onlyRecipientFee),

                'pricePickup'                  => $this->helper->getMethodPrice($carrierPath[$carrier], 'pickup/fee'),
                'pricePackageTypeMailbox'      => $this->helper->getMethodPrice($carrierPath[$carrier], 'mailbox/fee', false),
                'pricePackageTypeDigitalStamp' => $this->helper->getMethodPrice($carrierPath[$carrier], 'digital_stamp/fee', false),
            ];
        }

        return $myParcelConfig;
    }

    /**
     * Get the a list of the shipping methods.
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
    private function getDeliveryOptionsStrings()
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
        $carrierPath = data::CARRIERS_XML_PATH_MAP;
        $products    = $this->cart->getAllItems();
        $country     = $country ?? $this->cart->getShippingAddress()->getCountryId();

        $this->package->setCurrentCountry($country);
        $this->package->setDigitalStampActive($this->helper->getBoolConfig($carrierPath[$carrier], 'digital_stamp/active'));
        $this->package->setMailboxActive($this->helper->getBoolConfig($carrierPath[$carrier], 'mailbox/active'));
        $this->package->setWeightFromQuoteProducts($products);

        return $this->package->selectPackageType($products, $carrierPath[$carrier]);
    }

    /**
     * @param string $carrierPath
     *
     * @return bool
     */
    public function isAgeCheckActive(string $carrierPath): bool
    {
        $products    = $this->cart->getAllItems();
        $hasAgeCheck = $this->package->getAgeCheck($products, $carrierPath);

        return $hasAgeCheck;
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
}
