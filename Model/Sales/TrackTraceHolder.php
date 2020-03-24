<?php
/**
 * An object with the track and trace data
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <reindert@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Model\Sales;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelBE\Magento\Adapter\DeliveryOptionsFromOrderAdapter;
use MyParcelBE\Magento\Helper\Checkout;
use MyParcelBE\Magento\Helper\Data;
use MyParcelBE\Magento\Model\Source\DefaultOptions;
use MyParcelBE\Magento\Services\Normalizer\ConsignmentNormalizer;
use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\AbstractShipmentOptionsAdapter;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\Consignment\BpostConsignment;
use MyParcelNL\Sdk\src\Model\MyParcelCustomsItem;

/**
 * Class TrackTraceHolder
 * @package MyParcelBE\Magento\Model\Sales
 */
class TrackTraceHolder
{
    /**
     * Track title showing in Magento
     */
    const MYPARCEL_TRACK_TITLE  = 'MyParcel';
    const MYPARCEL_CARRIER_CODE = 'myparcelbe';

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \MyParcelBE\Magento\Model\Source\DefaultOptions
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
     * @var \MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment|null
     */
    public $consignment;

    /**
     * TrackTraceHolder constructor.
     *
     * @param ObjectManagerInterface     $objectManager
     * @param Data                       $helper
     * @param \Magento\Sales\Model\Order $order
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        Data $helper,
        Order $order
    ) {
        $this->objectManager  = $objectManager;
        $this->helper         = $helper;
        $this->messageManager = $this->objectManager->create('Magento\Framework\Message\ManagerInterface');
        self::$defaultOptions = new DefaultOptions(
            $order,
            $this->helper
        );
    }

    /**
     * Create Magento Track from Magento shipment
     *
     * @param Order\Shipment $shipment
     *
     * @return $this
     */
    public function createTrackTraceFromShipment(Order\Shipment &$shipment)
    {
        $this->mageTrack = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
        $this->mageTrack
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(self::MYPARCEL_CARRIER_CODE)
            ->setTitle(self::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber('concept');

        return $this;
    }

    /**
     * Set all data to MyParcel object
     *
     * @param Order\Shipment\Track $magentoTrack
     * @param array                $options
     *
     * @return $this
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function convertDataFromMagentoToApi($magentoTrack, $options)
    {
        $shipment        = $magentoTrack->getShipment();
        $address         = $shipment->getShippingAddress();
        $checkoutData    = $shipment->getOrder()->getData('myparcel_delivery_options');
        $packageType     = self::$defaultOptions->getPackageType();
        $deliveryOptions = json_decode($checkoutData, true);

        try {
            // create new instance from known json
            $deliveryOptionsAdapter = DeliveryOptionsAdapterFactory::create((array) $deliveryOptions);
        } catch (\BadMethodCallException $e) {
            // create new instance from unknown json data
            $deliveryOptions        = (new ConsignmentNormalizer((array) $deliveryOptions + $options))->normalize();
            $deliveryOptionsAdapter = new DeliveryOptionsFromOrderAdapter($deliveryOptions);
        }
        $pickupLocationAdapter  = $deliveryOptionsAdapter->getPickupLocation();
        $shippingOptionsAdapter = $deliveryOptionsAdapter->getShipmentOptions();

        $apiKey = $this->helper->getGeneralConfig(
            'api/key',
            $shipment->getOrder()->getStoreId()
        );

        $this->validateApiKey($apiKey);

        $this->consignment = (ConsignmentFactory::createByCarrierName($deliveryOptionsAdapter->getCarrier()))
            ->setApiKey($apiKey)
            ->setReferenceId($shipment->getEntityId())
            ->setConsignmentId($magentoTrack->getData('myparcel_consignment_id'))
            ->setCountry($address->getCountryId())
            ->setCompany($address->getCompany())
            ->setPerson($address->getName());

        try {
            $this->consignment->setFullStreet($address->getData('street'));
        } catch (\Exception $e) {
            $errorHuman = 'An error has occurred while validating the address: ' . $address->getData('street') . '. Check number and number suffix.';
            $this->messageManager->addErrorMessage($errorHuman . ' View log file for more information.');
            $this->objectManager->get('Psr\Log\LoggerInterface')->critical($errorHuman . '-' . $e);
        }

        if ($address->getPostcode() == null && $address->getCountryId() == 'BE') {
            $errorHuman = 'An error has occurred while validating the order number ' . $magentoTrack->getOrderId() . '. Postcode is required.';
            $this->messageManager->addErrorMessage($errorHuman . ' View log file for more information.');
            $this->objectManager->get('Psr\Log\LoggerInterface')->critical($errorHuman);
        }

        $this->consignment
            ->setPostalCode($address->getPostcode())
            ->setCity($address->getCity())
            ->setPhone($address->getTelephone())
            ->setEmail($address->getEmail())
            ->setLabelDescription($shipment->getOrder()->getIncrementId())
            ->setDeliveryDate($deliveryOptionsAdapter->getDate())
            ->setDeliveryType($deliveryOptionsAdapter->getDeliveryTypeId())
            ->setPackageType($packageType)
            ->setSignature($shippingOptionsAdapter->hasSignature())
            ->setInsurance($shippingOptionsAdapter->getInsurance())
            ->setInvoice('');

        if ($deliveryOptionsAdapter->isPickup()) {
            $this->consignment
                ->setPickupPostalCode($pickupLocationAdapter->getPostalCode())
                ->setPickupStreet($pickupLocationAdapter->getStreet())
                ->setPickupCity($pickupLocationAdapter->getCity())
                ->setPickupNumber($pickupLocationAdapter->getNumber())
                ->setPickupCountry($pickupLocationAdapter->getCountry())
                ->setPickupLocationName($pickupLocationAdapter->getLocationName())
                ->setPickupLocationCode($pickupLocationAdapter->getLocationCode())
                ->setPickupNetworkId($pickupLocationAdapter->getPickupNetworkId());
        }

        $this->convertDataForCdCountry($magentoTrack)
             ->calculateTotalWeight($magentoTrack);

        return $this;
    }

    /**
     * Override to check if key isset
     *
     * @param string $apiKey
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function validateApiKey($apiKey)
    {
        if ($apiKey == null) {
            throw new LocalizedException(__('API key is not known. Go to the settings in the backoffice to create an API key. Fill the API key in the settings.'));
        }

        return $this;
    }

    /**
     * @param Order\Shipment\Track $magentoTrack
     *
     * @return $this
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     * @throws \Exception
     */
    private function convertDataForCdCountry($magentoTrack)
    {
        if (! $this->consignment->isCdCountry()) {
            return $this;
        }

        if ($products = $magentoTrack->getShipment()->getData('items')) {
            foreach ($products as $product) {
                $myParcelProduct = (new MyParcelCustomsItem())
                    ->setDescription($product->getName())
                    ->setAmount($product->getQty())
                    ->setWeight($product->getWeight() ?: 1)
                    ->setItemValue($this->getCentsByPrice($product->getPrice()))
                    ->setClassification('0000')
                    ->setCountry('BE');
                $this->consignment->addItem($myParcelProduct);
            }
        }

        $products = $this->getItemsCollectionByShipmentId($magentoTrack->getShipment()->getId());

        foreach ($products as $product) {
            $myParcelProduct = (new MyParcelCustomsItem())
                ->setDescription($product['name'])
                ->setAmount($product['qty'])
                ->setWeight($product['weight'] ?: 1)
                ->setItemValue($this->getCentsByPrice($product['price']))
                ->setClassification('0000')
                ->setCountry('BE');

            $this->consignment->addItem($myParcelProduct);
        }

        return $this;
    }

    /**
     * Get default value if option === null
     *
     * @param $options []
     * @param $optionKey
     *
     * @return bool
     * @internal param $option
     *
     */
    private function getValueOfOption($options, $optionKey)
    {
        if ($options[$optionKey] === null) {
            return (bool) self::$defaultOptions->getDefault($optionKey);
        } else {
            return (bool) $options[$optionKey];
        }
    }


    /**
     * @param $shipmentId
     *
     * @return array
     */
    private function getItemsCollectionByShipmentId($shipmentId)
    {
        /** @var \Magento\Framework\App\ResourceConnection $connection */
        $connection = $this->objectManager->create('\Magento\Framework\App\ResourceConnection');
        $conn       = $connection->getConnection();
        $select     = $conn->select()
                           ->from(
                               ['main_table' => $connection->getTableName('sales_shipment_item')]
                           )
                           ->where('main_table.parent_id=?', $shipmentId);
        $items      = $conn->fetchAll($select);

        return $items;
    }

    /**
     * @param bool|null $signature
     *
     * @return bool
     */
    protected function isSignature(?bool $signature): bool
    {
        if ($signature !== null) {
            return (bool) $signature;
        }

        return (bool) self::$defaultOptions->getDefault('signature');
    }

    /**
     * @param \MyParcelNL\Sdk\src\Adapter\DeliveryOptions\AbstractShipmentOptionsAdapter|null $shippingOptionsAdapter
     *
     * @return int|null
     */
    protected function hasInsurance(?AbstractShipmentOptionsAdapter $shippingOptionsAdapter)
    {
        if ($shippingOptionsAdapter->getInsurance() !== null) {
            return $shippingOptionsAdapter->getInsurance();
        }

        return self::$defaultOptions->getDefaultInsurance();
    }

    /**
     * @param Order\Shipment\Track $magentoTrack
     * @param int                  $totalWeight
     *
     * @return TrackTraceHolder
     * @throws LocalizedException
     * @throws \Exception
     */
    private function calculateTotalWeight($magentoTrack, int $totalWeight = 0): self
    {
        if ($this->consignment->getPackageType() !== AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP) {
            return $this;
        }

        if ($totalWeight > 0) {
            $this->consignment->setPhysicalProperties(["weight" => $totalWeight]);

            return $this;
        }

        $weightFromSettings = (int) self::$defaultOptions->getDigitalStampWeight();
        if ($weightFromSettings) {
            $this->consignment->setPhysicalProperties(["weight" => $weightFromSettings]);

            return $this;
        }

        if ($products = $magentoTrack->getShipment()->getData('items')) {
            foreach ($products as $product) {
                $totalWeight += $product->consignment->getWeight();
            }
        }

        $products = $this->getItemsCollectionByShipmentId($magentoTrack->getShipment()->getId());

        foreach ($products as $product) {
            $totalWeight += $product['weight'];
        }

        if ($totalWeight == 0) {
            throw new Exception('The order with digital stamp can not be exported, no weights have been entered');
        }

        $this->consignment->setPhysicalProperties([
            "weight" => $totalWeight
        ]);

        return $this;
    }

    /**
     * @param float $price
     *
     * @return int
     */
    private function getCentsByPrice(float $price): int
    {
        return (int) $price * 100;
    }
}
