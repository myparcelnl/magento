<?php
/**
 * Show MyParcel options in order detailpage
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

namespace MyParcelNL\Magento\Block\Sales;

use DateTime;
use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Block\Adminhtml\Order\AbstractOrder;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter;
use MyParcelNL\Sdk\Factory\DeliveryOptionsAdapterFactory;
use Throwable;

class View extends AbstractOrder
{
    /**
     * Collect options selected at checkout and calculate type consignment
     *
     * @return string
     * @throws LocalizedException
     * @throws Exception
     */
    public function getCheckoutOptionsHtml(): string
    {
        $order = $this->getOrder();

        /** @var object $data Data from checkout */
        $data = json_decode($order->getData(Config::FIELD_DELIVERY_OPTIONS) ?? null, true);

        if (!is_array($data)) {
            return '';
        }

        $deliveryOptions = DeliveryOptionsAdapterFactory::create((array) $data);
        $returnString    = '';

        try {
            if ($deliveryOptions->isPickup()) {
                $returnString = htmlentities($this->getCheckoutOptionsPickupHtml($deliveryOptions));
            }

            if ($deliveryOptions->getDate()) {
                $returnString = htmlentities($this->getCheckoutOptionsDeliveryHtml($deliveryOptions));
            }
        } catch (Throwable $e) {
            Logger::critical($e->getMessage());
            $returnString = __('MyParcel options data not found');
        }

        return $returnString;
    }

    /**
     * @param AbstractDeliveryOptionsAdapter $deliveryOptions
     * @return string
     */
    private function getCheckoutOptionsPickupHtml(AbstractDeliveryOptionsAdapter $deliveryOptions): string
    {
        ob_start();

        echo __("{$deliveryOptions->getCarrier()} location:"), ' ';

        if ('pickup' !== $deliveryOptions->getDeliveryType()) {
            echo __($deliveryOptions->getDeliveryType()), ', ';
        }

        $pickupLocation = $deliveryOptions->getPickupLocation();

        if (null !== $pickupLocation) {
            echo $pickupLocation->getLocationName(), ', ';
            echo $pickupLocation->getCity(), ' (', $pickupLocation->getPostalCode(), ')';
        }

        return ob_get_clean();
    }

    /**
     * @param AbstractDeliveryOptionsAdapter $deliveryOptions
     * @return string
     * @throws Exception
     */
    private function getCheckoutOptionsDeliveryHtml(AbstractDeliveryOptionsAdapter $deliveryOptions): string
    {
        ob_start();

        if ($deliveryOptions->getPackageType()) {
            echo __($deliveryOptions->getPackageType()), ' ';
        }

        $date = new DateTime($deliveryOptions->getDate() ?? '');

        echo __('Deliver:'), ' ', $date->format('d-m-Y H:i');

        $shipmentOptions = $deliveryOptions->getShipmentOptions();

        if (null !== $shipmentOptions) {
            if ($shipmentOptions->hasSignature()) {
                echo ', ', __('Signature on receipt');
            }
            if ($shipmentOptions->hasOnlyRecipient()) {
                echo ', ', __('Home address only');
            }
        }

        return ob_get_clean();
    }
}
