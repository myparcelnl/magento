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
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Model\Quote;


use Magento\Framework\Event\ObserverInterface;
use MyParcelNL\Magento\Model\Sales\Repository\DeliveryRepository;
use MyParcelNL\Sdk\src\Model\Repository\MyParcelConsignmentRepository;

class SaveOrderBeforeSalesModelQuoteObserver implements ObserverInterface
{
    const FIELD_DELIVERY_OPTIONS = 'delivery_options';
    const FIELD_DROP_OFF_DAY = 'drop_off_day';
    const FIELD_TRACK_STATUS = 'track_status';
    /**
     * @var DeliveryRepository
     */
    private $delivery;
    /**
     * @var MyParcelConsignmentRepository
     */
    private $consignmentRepository;

    /**
     * SaveOrderBeforeSalesModelQuoteObserver constructor.
     * @param DeliveryRepository $delivery
     * @param MyParcelConsignmentRepository $consignmentRepository
     */
    public function __construct(DeliveryRepository $delivery, MyParcelConsignmentRepository $consignmentRepository)
    {
        $this->delivery = $delivery;
        $this->consignmentRepository = $consignmentRepository;
    }

    /**
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /* @var \Magento\Quote\Model\Quote $quote */
        $quote = $observer->getEvent()->getData('quote');
        /* @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getData('order');
        $fullStreet = implode(' ', $order->getShippingAddress()->getStreet());

        if ($order->getShippingAddress()->getCountryId() == 'NL' && $this->consignmentRepository->isCorrectAddress($fullStreet) == false) {
            $order->setData(self::FIELD_TRACK_STATUS, __('⚠️&#160; Please check address'));
        }

        if ($quote->hasData(self::FIELD_DELIVERY_OPTIONS)) {
            $jsonDeliveryOptions = $quote->getData(self::FIELD_DELIVERY_OPTIONS);
            $order->setData(self::FIELD_DELIVERY_OPTIONS, $jsonDeliveryOptions);

            $dropOffDay = $this->delivery->getDropOffDayFromJson($jsonDeliveryOptions);
            $order->setData(self::FIELD_DROP_OFF_DAY, $dropOffDay);
        }

        return $this;
    }

}
