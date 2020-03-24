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
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelBE\Magento\Model\Quote;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MyParcelBE\Magento\Helper\Checkout;
use MyParcelBE\Magento\Model\Checkout\Carrier;
use MyParcelBE\Magento\Model\Checkout\DeliveryOptions;
use MyParcelBE\Magento\Helper\Checkout as CheckoutAlias;
use MyParcelBE\Magento\Model\Sales\Repository\DeliveryRepository;
use MyParcelNL\Sdk\src\Helper\SplitStreet;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class SaveOrderBeforeSalesModelQuoteObserver implements ObserverInterface
{
    /**
     * @var DeliveryRepository
     */
    private $delivery;
    /**
     * @var AbstractConsignment
     */
    private $consignment;
    /**
     * @var array
     */
    private $parentMethods;

    /**
     * SaveOrderBeforeSalesModelQuoteObserver constructor.
     *
     * @param DeliveryRepository  $delivery
     * @param AbstractConsignment $consignment
     * @param Checkout            $checkoutHelper
     */
    public function __construct(
        DeliveryRepository $delivery,
        AbstractConsignment $consignment,
        Checkout $checkoutHelper
    ) {
        $this->delivery      = $delivery;
        $this->consignment   = $consignment;
        $this->parentMethods = explode(',', $checkoutHelper->getGeneralConfig('shipping_methods/methods'));
    }

    /**
     *
     * @param \Magento\Framework\Event\Observer $observer
     *
     * @return $this
     */
    public function execute(Observer $observer)
    {
        /* @var \Magento\Quote\Model\Quote $quote */
        $quote = $observer->getEvent()->getData('quote');
        file_put_contents(time() . '_quote.json', json_encode($quote->getData()));

        /* @var \Magento\Sales\Model\Order $order */
        $order      = $observer->getEvent()->getData('order');
        $fullStreet = implode(' ', $order->getShippingAddress()->getStreet());

        $destinationCountry = $order->getShippingAddress()->getCountryId();
        if ($destinationCountry == AbstractConsignment::CC_BE &&
            ! SplitStreet::isCorrectStreet($fullStreet, AbstractConsignment::CC_BE, $destinationCountry)
        ) {
            $order->setData(CheckoutAlias::FIELD_TRACK_STATUS, __('⚠️&#160; Please check address'));
        }
        // @todo check delivery options from quote (step 2)
        if ($quote->hasData(Checkout::FIELD_DELIVERY_OPTIONS && $this->hasMyParcelDeliveryOptions($quote))) {
            $jsonDeliveryOptions = $quote->getData(Checkout::FIELD_DELIVERY_OPTIONS);

            $order->setData(Checkout::FIELD_DELIVERY_OPTIONS, $jsonDeliveryOptions);

            $dropOffDay = $this->delivery->getDropOffDayFromJson($jsonDeliveryOptions);
            $order->setData(Checkout::FIELD_DROP_OFF_DAY, $dropOffDay);

            $selectedCarrier = $this->delivery->getCarrierFromJson($jsonDeliveryOptions);
            $order->setData(Checkout::FIELD_MYPARCEL_CARRIER, $selectedCarrier);
        }

        return $this;
    }

    /**
     * @param \Magento\Quote\Model\Quote $quote
     *
     * @return bool
     */
    private function hasMyParcelDeliveryOptions($quote)
    {
        file_put_contents(time() . '_hasMyParcelDeliveryOptions.json', json_encode($quote->getData()));
        $myParcelMethods = array_keys(Carrier::getMethods());
        $shippingMethod  = $quote->getShippingAddress()->getShippingMethod();

        if ($this->arrayLike($shippingMethod, $myParcelMethods)) {
            return true;
        }

        if ($this->arrayLike($shippingMethod, $this->parentMethods)) {
            return true;
        }

        return array_key_exists('myparcel_delivery_options', $quote->getData());
    }

    /**
     * @param $input
     * @param $data
     *
     * @return bool
     */
    private function arrayLike($input, $data)
    {
        $result = array_filter($data, function($item) use ($input) {
            if (stripos($input, $item) !== false) {
                return true;
            }

            return false;
        });

        return count($result) > 0;
    }
}
