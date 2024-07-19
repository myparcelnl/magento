<?php
/**
 * Save delivery date and delivery options
 *
 * Plugin from Magento\Checkout\Model\ShippingInformationManagement
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelBE\Magento\Model\Quote;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use MyParcelBE\Magento\Helper\Checkout;
use MyParcelBE\Magento\Model\Checkout\Carrier;
use MyParcelBE\Magento\Model\Sales\Repository\DeliveryRepository;
use MyParcelNL\Sdk\src\Helper\ValidatePostalCode;
use MyParcelNL\Sdk\src\Helper\ValidateStreet;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class SaveOrderBeforeSalesModelQuoteObserver implements ObserverInterface
{
    /**
     * @var DeliveryRepository
     */
    private $delivery;

    /**
     * @var array
     */
    private $parentMethods;

    /**
     * SaveOrderBeforeSalesModelQuoteObserver constructor.
     *
     * @param DeliveryRepository  $delivery
     * @param Checkout            $checkoutHelper
     */
    public function __construct(
        DeliveryRepository $delivery,
        Checkout $checkoutHelper
    ) {
        $this->delivery      = $delivery;
        $this->parentMethods = explode(',', $checkoutHelper->getGeneralConfig('shipping_methods/methods') ?? '');
    }

    /**
     *
     * @param Observer $observer
     *
     * @return $this
     */
    public function execute(Observer $observer)
    {
        /* @var Quote $quote */
        $quote = $observer->getEvent()->getData('quote');

        /* @var Order $order */
        $order = $observer->getEvent()->getData('order');

        if ($order->getShippingAddress() === null) {
            return $this;
        }

        $fullStreet         = implode(' ', $order->getShippingAddress()->getStreet() ?? []);
        $postcode           = $order->getShippingAddress()->getPostcode();
        $destinationCountry = $order->getShippingAddress()->getCountryId();

        if (! ValidateStreet::validate($fullStreet, AbstractConsignment::CC_NL, $destinationCountry)) {
            $order->setData(Checkout::FIELD_TRACK_STATUS, __('⚠️&#160; Please check street'));
        }

        if (! ValidatePostalCode::validate($postcode, $destinationCountry)) {
            $order->setData(Checkout::FIELD_TRACK_STATUS, __('⚠️&#160; Please check postal code'));
        }

        if ($quote->hasData(Checkout::FIELD_DELIVERY_OPTIONS) && $this->hasMyParcelDeliveryOptions($quote)) {
            $jsonDeliveryOptions = $quote->getData(Checkout::FIELD_DELIVERY_OPTIONS) ?? '';
            $deliveryOptions     = json_decode($jsonDeliveryOptions, true) ?? [];

            $order->setData(Checkout::FIELD_DELIVERY_OPTIONS, $jsonDeliveryOptions);

            $dropOffDay = $this->delivery->getDropOffDayFromDeliveryOptions($deliveryOptions);
            $order->setData(Checkout::FIELD_DROP_OFF_DAY, $dropOffDay);

            $selectedCarrier = $this->delivery->getCarrierFromDeliveryOptions($deliveryOptions);
            $order->setData(Checkout::FIELD_MYPARCEL_CARRIER, $selectedCarrier);
        }

        return $this;
    }

    /**
     * @param  Quote $quote
     *
     * @return bool
     */
    private function hasMyParcelDeliveryOptions(Quote $quote): bool
    {
        $myParcelMethods = array_keys(Carrier::getMethods());
        $shippingMethod  = $quote->getShippingAddress()->getShippingMethod();

        if ($this->isMyParcelRelated($shippingMethod, $myParcelMethods)) {
            return true;
        }

        if ($this->isMyParcelRelated($shippingMethod, $this->parentMethods)) {
            return true;
        }

        return array_key_exists('myparcel_delivery_options', $quote->getData());
    }

    /**
     * @param string $input
     * @param array  $data
     *
     * @return bool
     */
    private function isMyParcelRelated(string $input, array $data): bool
    {
        $result = array_filter(
            $data,
            static function ($item) use ($input) {
                return stripos($input, $item) !== false;
            }
        );

        return count($result) > 0;
    }
}
