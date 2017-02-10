<?php
/**
 * An object with the track and trace data
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Sales;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\src\Model\Repository\MyParcelConsignmentRepository;
use MyParcelNL\Magento\Helper\Data;

class MyParcelTrackTrace extends MyParcelConsignmentRepository
{

    /**
     * Recipient email config path
     */
    const CONFIG_PATH_BASE_API_KEY = 'basic_settings/print/paper_type';

    /**
     * Track title showing in Magento
     */
    const POSTNL_TRACK_TITLE = 'MyParcel';
    const POSTNL_CARRIER_CODE = 'myparcelnl';

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \MyParcelNL\Magento\Model\Source\DefaultOptions
     */
    private static $defaultOptions;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var Order\Shipment\Track
     */
    public $mageTrack;

    /**
     * MyParcelTrackTrace constructor.
     *
     * @param ObjectManagerInterface      $objectManager
     * @param Data $helper
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        Data $helper
    ) {
        $this->objectManager = $objectManager;
        $this->helper = $helper;
    }

    /**
     * @param Order $order
     *
     * @return $this
     * @throws LocalizedException
     */
    public function createTrackTraceFromOrder(Order $order)
    {
        self::$defaultOptions = new DefaultOptions(
            $order,
            $this->objectManager->get('\MyParcelNL\Magento\Helper\Data')
        );

        if ($order->hasShipments()) {
            // Set new track and trace to first shipment
            foreach ($order->getShipmentsCollection() as $shipment) {
                $this->createTrackTraceFromShipment($shipment);
                break;
            }
        } elseif ($order->canShip()) {
            // Create shipment
            $shipment = $this->createShipment($order);
            $this->createTrackTraceFromShipment($shipment);
        } else {
            throw new LocalizedException(
                __('Error 500; Can\'t create shipment in ' . __CLASS__ . ':' . __LINE__)
            );
        }

        return $this;
    }

    /**
     * @param Order\Shipment $shipment
     *
     * @return $this
     */
    public function createTrackTraceFromShipment(Order\Shipment $shipment)
    {
        $this->mageTrack = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
        $this->mageTrack
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(self::POSTNL_CARRIER_CODE)
            ->setTitle(self::POSTNL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber('concept')
            ->save();

        return $this;
    }

    /**
     * @return $this
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function convertDataFromMagentoToApi()
    {
        $address = $this->mageTrack->getShipment()->getShippingAddress();

        $this
            ->setApiKey($this->helper->getGeneralConfig('api/key'))
            ->setReferenceId($this->mageTrack->getEntityId())
            ->setMyParcelConsignmentId($this->mageTrack->getData('myparcel_consignment_id'))
            ->setCountry($address->getCountryId())
            ->setCompany($address->getCompany())
            ->setPerson($address->getName())
            ->setFullStreet($address->getData('street'))
            ->setPostalCode($address->getPostcode())
            ->setCity($address->getCity())
            ->setPhone($address->getTelephone())
            ->setEmail($address->getEmail())
            ->setLabelDescription($this->mageTrack->getShipment()->getOrderId())
            ->setOnlyRecipient(self::$defaultOptions->getDefault('only_recipient'))
            ->setSignature(self::$defaultOptions->getDefault('signature'))
            ->setReturn(self::$defaultOptions->getDefault('return'))
            ->setLargeFormat(self::$defaultOptions->getDefault('large_format'))
            ->setInsurance(self::$defaultOptions->getDefaultInsurance());

        return $this;
    }

    /**
     * @param Order $order
     *
     * @return Order\Shipment
     * @throws LocalizedException
     */
    private function createShipment(Order $order)
    {
        /**
         * @var Order\Shipment $shipment
         */
        // Initialize the order shipment object
        $convertOrder = $this->objectManager->create('Magento\Sales\Model\Convert\Order');
        $shipment = $convertOrder->toShipment($order);

        // Loop through order items
        foreach ($order->getAllItems() as $orderItem) {
            // Check if order item has qty to ship or is virtual
            if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                continue;
            }

            $qtyShipped = $orderItem->getQtyToShip();

            // Create shipment item with qty
            $shipmentItem = $convertOrder->itemToShipmentItem($orderItem)->setQty($qtyShipped);

            // Add shipment item to shipment
            $shipment->addItem($shipmentItem);
        }

        // Register shipment
        $shipment->register();

        $shipment->getOrder()->setIsInProcess(true);

        try {
            // Save created shipment and order
            $shipment->save();

            // Send email
            $this->objectManager->create('Magento\Shipping\Model\ShipmentNotifier')
                ->notify($shipment);

            return $shipment;
        } catch (\Exception $e) {
            throw new LocalizedException(
                __($e->getMessage())
            );
        }
    }

    /**
     * Override to check if key isset
     *
     * @param string $apiKey
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function setApiKey($apiKey)
    {
        if ($apiKey == null) {
            throw new LocalizedException(__('API key is not known. Go to the settings in the back office of MyParcel to create an API key. Fill the API key in the settings.'));
        }
        parent::setApiKey($apiKey);
        return $this;
    }
}
