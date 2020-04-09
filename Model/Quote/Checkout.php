<?php

namespace MyParcelNL\Magento\Model\Quote;

use Magento\Checkout\Model\Cart;
use Magento\Checkout\Model\Session;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Checkout\Carrier;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use \Magento\Store\Model\StoreManagerInterface;

class Checkout
{
    const selectCarriersArray = 0;
    const selectCarrierPath   = 1;
    const platform            = 'myparcel';

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
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getDeliveryOptions(): array
    {
        $this->helper->setBasePriceFromQuote($this->quoteId);

        $this->data = [
            'methods' => explode(';', $this->getDeliveryMethods()),
            'config'  => array_merge(
                $this->getGeneralData(),
                $this->getDeliveryData()
            ),
            'strings' => $this->getDeliveryOptionsStrings(),
        ];

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
            'platform'                   => self::platform,
            'carriers'                   => array_column($this->get_carriers(), self::selectCarriersArray),
            'currency'                   => $this->currency->getStore()->getCurrentCurrency()->getCode(),
            'pickupLocationsDefaultView' => $this->helper->getArrayConfig(Data::XML_PATH_GENERAL, 'shipping_methods/pickup_locations_view')
        ];
    }

    /**
     * Get delivery data
     *
     * @return array
     */
    private function getDeliveryData(): array
    {

        $carriersPath   = $this->get_carriers();
        $myParcelConfig = [];

        foreach ($carriersPath as $carrier) {
            $myParcelConfig["carrierSettings"][$carrier[self::selectCarriersArray]] = [
                'allowDeliveryOptions'      => $this->helper->getBoolConfig($carrier[self::selectCarrierPath], 'delivery/active'),
                'allowSignature'            => $this->helper->getBoolConfig($carrier[self::selectCarrierPath], 'delivery/signature_active'),
                'allowOnlyRecipient'        => $this->helper->getBoolConfig($carrier[self::selectCarrierPath], 'delivery/only_recipient_active'),
                'allowMorningDelivery'      => $this->helper->getBoolConfig($carrier[self::selectCarrierPath], 'morning/active'),
                'allowEveningDelivery'      => $this->helper->getBoolConfig($carrier[self::selectCarrierPath], 'evening/active'),
                'allowMailboxDelivery'      => $this->package->isMailboxPackage($this->getMailboxData($carrier)),
                'allowDigitalStampDelivery' => $this->helper->getBoolConfig($carrier[self::selectCarrierPath], 'digital_stamp/active'),
                'allowPickupLocations'      => $this->helper->getBoolConfig($carrier[self::selectCarrierPath], 'pickup/active'),

                'priceSignature'            => $this->helper->getMethodPriceFormat($carrier[self::selectCarrierPath], 'delivery/signature_fee', false),
                'priceOnlyRecipient'        => $this->helper->getMethodPriceFormat($carrier[self::selectCarrierPath], 'delivery/only_recipient_fee', false),
                'priceStandardDelivery'     => $this->helper->getMoneyFormat($this->helper->getBasePrice()),
                'priceMorningDelivery'      => $this->helper->getMethodPriceFormat($carrier[self::selectCarrierPath], 'morning/fee', false),
                'priceEveningDelivery'      => $this->helper->getMethodPriceFormat($carrier[self::selectCarrierPath], 'evening/fee', false),
                'priceMailboxDelivery'      => $this->helper->getMethodPriceFormat($carrier[self::selectCarrierPath], 'mailbox/fee', false),
                'priceDigitalStampDelivery' => $this->helper->getMethodPriceFormat($carrier[self::selectCarrierPath], 'digital_stamp/fee', false),
                'pricePickup'               => $this->helper->getMethodPriceFormat($carrier[self::selectCarrierPath], 'pickup/fee', false),

                'cutoffTime'          => $this->helper->getTimeConfig($carrier[self::selectCarrierPath], 'general/cutoff_time'),
                'saturdayCutoffTime'  => $this->helper->getTimeConfig($carrier[self::selectCarrierPath], 'general/saturday_cutoff_time'),
                'deliveryDaysWindow'  => $this->helper->getIntergerConfig($carrier[self::selectCarrierPath], 'general/deliverydays_window'),
                'allowMondayDelivery' => $this->helper->getIntergerConfig($carrier[self::selectCarrierPath], 'general/monday_delivery_active'),
                'dropOffDays'         => $this->helper->getArrayConfig($carrier[self::selectCarrierPath], 'general/dropoff_days'),
                'dropOffDelay'        => $this->helper->getIntergerConfig($carrier[self::selectCarrierPath], 'general/dropoff_delay'),
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
    private function get_carriers(): array
    {
        $carriersSettings = [
            ['postnl', Data::XML_PATH_POSTNL_SETTINGS],
//            ['dpd', Data::XML_PATH_DPD_SETTINGS]
        ];

        foreach ($carriersSettings as $carrier) {
            if ($this->helper->getBoolConfig("{$carrier[self::selectCarrierPath]}", 'general/enabled') ||
                $this->helper->getBoolConfig("{$carrier[self::selectCarrierPath]}", 'pickup/active')
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
            'mailboxTitle'              => $this->helper->getGeneralConfig('delivery_titles/mailbox_title'),
            'digitalStampTitle'         => $this->helper->getGeneralConfig('delivery_titles/digital_stamp_title'),
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
     * @param $carrier
     *
     * @return array
     */
    private function getMailboxData($carrier)
    {
        $cart          = $this->cart->getAllItems();
        $country       = $this->cart->getShippingAddress()->getCountryId();
        $active        = $this->helper->getBoolConfig($carrier[self::selectCarrierPath], 'mailbox/active');
        $defaultWeight = $this->helper->getIntergerConfig($carrier[self::selectCarrierPath], 'mailbox/weight');

        return [
            'active'        => $active,
            'cart'          => $cart,
            'country'       => $country,
            'defaultWeight' => $defaultWeight
        ];
    }
}
