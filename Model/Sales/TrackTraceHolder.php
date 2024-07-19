<?php
/**
 * An object with the track and trace data
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @copyright   2010-2019 MyParcel
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Model\Sales;

use BadMethodCallException;
use Exception;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelBE\Magento\Adapter\DeliveryOptionsFromOrderAdapter;
use MyParcelBE\Magento\Helper\Data;
use MyParcelBE\Magento\Helper\ShipmentOptions;
use MyParcelBE\Magento\Model\Source\DefaultOptions;
use MyParcelBE\Magento\Services\Normalizer\ConsignmentNormalizer;
use MyParcelBE\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\src\Exception\MissingFieldException;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\MyParcelCustomsItem;
use RuntimeException;

/**
 * Class TrackTraceHolder
 *
 * @package MyParcelBE\Magento\Model\Sales
 */
class TrackTraceHolder
{
    /**
     * Track title showing in Magento
     */
    public const MYPARCEL_TRACK_TITLE  = 'MyParcel';
    public const MYPARCEL_CARRIER_CODE = 'myparcel';
    public const EXPORT_MODE_PPS       = 'pps';
    public const EXPORT_MODE_SHIPMENTS = 'shipments';

    /**
     * @var \MyParcelBE\Magento\Model\Source\DefaultOptions
     */
    private static $defaultOptions;

    /**
     * @var \MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment|null
     */
    public $consignment;

    /**
     * @var Order\Shipment\Track
     */
    public $mageTrack;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var string|null
     */
    private $carrier;

    /**
     * @var \MyParcelBE\Magento\Helper\Data
     */
    private $dataHelper;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \MyParcelBE\Magento\Helper\ShipmentOptions
     */
    private $shipmentOptionsHelper;

    /**
     * TrackTraceHolder constructor.
     *
     * @param  ObjectManagerInterface     $objectManager
     * @param  Data                       $helper
     * @param  \Magento\Sales\Model\Order $order
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        Data                   $helper,
        Order                  $order
    ) {
        $this->objectManager  = $objectManager;
        $this->dataHelper     = $helper;
        $this->messageManager = $this->objectManager->create('Magento\Framework\Message\ManagerInterface');
        self::$defaultOptions = new DefaultOptions(
            $order,
            $this->dataHelper
        );
    }

    /**
     * @param  float $price
     *
     * @return int
     */
    public static function getCentsByPrice(float $price): int
    {
        return (int) $price * 100;
    }

    /**
     * Set all data to MyParcel object
     *
     * @param  Order\Shipment\Track $magentoTrack
     * @param  array                $options
     *
     * @return $this
     * @throws \Exception
     * @throws LocalizedException
     */
    public function convertDataFromMagentoToApi(Track $magentoTrack, array $options): self
    {
        $shipment                       = $magentoTrack->getShipment();
        $address                        = $shipment->getShippingAddress();
        $order                          = $shipment->getOrder();
        $checkoutData                   = $order->getData('myparcel_delivery_options') ?? '';
        $deliveryOptions                = json_decode($checkoutData, true) ?? [];
        $deliveryOptions['carrier']     = $this->getCarrierFromOptions($options)
            ?? $deliveryOptions['carrier']
            ?? DefaultOptions::getDefaultCarrier()
                ->getName();

        $totalWeight = $options['digital_stamp_weight'] !== null ? (int) $options['digital_stamp_weight']
            : (int) self::$defaultOptions->getDigitalStampDefaultWeight();

        try {
            // create new instance from known json
            $deliveryOptionsAdapter = DeliveryOptionsAdapterFactory::create((array) $deliveryOptions);
        } catch (BadMethodCallException $e) {
            // create new instance from unknown json data
            $deliveryOptions        = (new ConsignmentNormalizer((array) $deliveryOptions + $options))->normalize();
            $deliveryOptionsAdapter = new DeliveryOptionsFromOrderAdapter($deliveryOptions);
        }

        $pickupLocationAdapter = $deliveryOptionsAdapter->getPickupLocation();
        $apiKey                = $this->dataHelper->getGeneralConfig(
            'api/key',
            $order->getStoreId()
        );

        $this->validateApiKey($apiKey);
        $this->carrier               = $deliveryOptionsAdapter->getCarrier();
        $this->shipmentOptionsHelper = new ShipmentOptions(
            self::$defaultOptions,
            $this->dataHelper,
            $order,
            $this->objectManager,
            $this->carrier,
            $options
        );

        $this->consignment = (ConsignmentFactory::createByCarrierName($deliveryOptionsAdapter->getCarrier()))
            ->setApiKey($apiKey)
            ->setReferenceIdentifier($shipment->getEntityId())
            ->setConsignmentId($magentoTrack->getData('myparcel_consignment_id'))
            ->setCountry($address->getCountryId())
            ->setCompany(self::$defaultOptions->getMaxCompanyName($address->getCompany()))
            ->setPerson($address->getName());

        try {
            $this->consignment
                ->setFullStreet($address->getData('street'))
                ->setPostalCode(preg_replace('/\s+/', '', $address->getPostcode()));
        } catch (Exception $e) {
            $errorHuman =
                sprintf(
                    'An error has occurred while validating order number %s. Check address.',
                    $order->getIncrementId()
                );
            $this->messageManager->addErrorMessage($errorHuman . ' View log file for more information.');
            $this->objectManager->get('Psr\Log\LoggerInterface')
                ->critical($errorHuman . '-' . $e);

            $this->dataHelper->setOrderStatus($magentoTrack->getOrderId(), Order::STATE_NEW);
        }

        if (isset($deliveryOptions['packageType'])) {
            $options['package_type'] = $deliveryOptions['packageType'];
        }
        $packageType  = $this->getPackageType($options, $magentoTrack, $address);
        $dropOffPoint = $this->dataHelper->getDropOffPoint(
            CarrierFactory::createFromName($deliveryOptionsAdapter->getCarrier())
        );

        $regionCode = $address->getRegionCode();
        $state = $regionCode && strlen($regionCode) === 2 ? $regionCode : null;

        $this->consignment
            ->setCity($address->getCity())
            ->setState($state)
            ->setPhone($address->getTelephone())
            ->setEmail($address->getEmail())
            ->setLabelDescription($this->shipmentOptionsHelper->getLabelDescription())
            ->setDeliveryDate($this->dataHelper->convertDeliveryDate($deliveryOptionsAdapter->getDate()))
            ->setDeliveryType($this->dataHelper->checkDeliveryType($deliveryOptionsAdapter->getDeliveryTypeId()))
            ->setPackageType($packageType)
            ->setDropOffPoint($dropOffPoint)
            ->setOnlyRecipient($this->shipmentOptionsHelper->hasOnlyRecipient())
            ->setSignature($this->shipmentOptionsHelper->hasSignature())
            ->setReturn($this->shipmentOptionsHelper->hasReturn())
            ->setSameDayDelivery($this->shipmentOptionsHelper->hasSameDayDelivery())
            ->setLargeFormat($this->shipmentOptionsHelper->hasLargeFormat())
            ->setAgeCheck($this->shipmentOptionsHelper->hasAgeCheck())
            ->setInsurance($this->shipmentOptionsHelper->getInsurance())
            ->setInvoice(
                $shipment
                    ->getOrder()
                    ->getIncrementId()
            )
            ->setSaveRecipientAddress(false);

        if ($deliveryOptionsAdapter->isPickup() && $pickupLocationAdapter) {
            $this->consignment
                ->setPickupPostalCode($pickupLocationAdapter->getPostalCode())
                ->setPickupStreet($pickupLocationAdapter->getStreet())
                ->setPickupCity($pickupLocationAdapter->getCity())
                ->setPickupNumber($pickupLocationAdapter->getNumber())
                ->setPickupCountry($pickupLocationAdapter->getCountry())
                ->setPickupLocationName($pickupLocationAdapter->getLocationName())
                ->setPickupLocationCode($pickupLocationAdapter->getLocationCode())
                ->setPickupNetworkId($pickupLocationAdapter->getPickupNetworkId())
                ->setReturn(false);

            if ($pickupLocationAdapter->getRetailNetworkId()) {
                $this->consignment->setRetailNetworkId($pickupLocationAdapter->getRetailNetworkId());
            }
        }

        try {
            $this->convertDataForCdCountry($magentoTrack)
                ->calculateTotalWeight($magentoTrack, $totalWeight);
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this;
        }

        return $this;
    }

    /**
     * Create Magento Track from Magento shipment
     *
     * @param  \Magento\Sales\Model\Order\Shipment $shipment
     *
     * @return $this
     */
    public function createTrackTraceFromShipment(Shipment $shipment)
    {
        $this->mageTrack = $this->objectManager->create(Track::class);
        $this->mageTrack
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(self::MYPARCEL_CARRIER_CODE)
            ->setTitle(self::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber(TrackAndTrace::VALUE_EMPTY);

        return $this;
    }

    /**
     * Get country of origin from product settings or, if they are not found, from the MyParcel settings.
     *
     * @param $product_id
     *
     * @return string
     */
    public function getCountryOfOrigin(int $product_id): string
    {
        $product                     =
            $this->objectManager->get('Magento\Catalog\Api\ProductRepositoryInterface')
                ->getById($product_id);
        $productCountryOfManufacture = $product->getCountryOfManufacture();

        if ($productCountryOfManufacture) {
            return $productCountryOfManufacture;
        }

        return $this->dataHelper->getGeneralConfig('print/country_of_origin');
    }

    /**
     * Override to check if key isset
     *
     * @param  null|string $apiKey
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function validateApiKey(?string $apiKey): self
    {
        if (null === $apiKey) {
            throw new LocalizedException(
                __(
                    'API key is not known. Go to the settings in the backoffice to create an API key. Fill the API key in the settings.'
                )
            );
        }

        return $this;
    }

    /**
     * @param  Order\Shipment\Track $magentoTrack
     * @param  int                  $totalWeight
     *
     * @return TrackTraceHolder
     * @throws LocalizedException
     * @throws \Exception
     */
    private function calculateTotalWeight(Track $magentoTrack, int $totalWeight = 0): self
    {
        if ($this->consignment->getPackageType() !== AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP) {
            return $this;
        }

        if ($totalWeight > 0) {
            $this->consignment->setPhysicalProperties(["weight" => $totalWeight]);

            return $this;
        }

        $weightFromSettings = (int) self::$defaultOptions->getDigitalStampDefaultWeight();
        if ($weightFromSettings) {
            $this->consignment->setPhysicalProperties(["weight" => $weightFromSettings]);

            return $this;
        }

        $shipmentItems =
            $magentoTrack->getShipment()
                ->getItems();

        foreach ($shipmentItems as $shipmentItem) {
            $totalWeight += $shipmentItem['weight'] * $shipmentItem['qty'];
        }

        $totalWeight = $this->dataHelper->convertToGrams($totalWeight);

        if (0 === $totalWeight) {
            throw new RuntimeException(
                sprintf(
                    'Order %s can not be exported as digital stamp, no weights have been entered.',
                    $magentoTrack->getShipment()
                        ->getOrder()
                        ->getIncrementId()
                )
            );
        }

        $this->consignment->setPhysicalProperties([
            'weight' => $totalWeight,
        ]);

        return $this;
    }

    /**
     * @param  Order\Shipment\Track $magentoTrack
     *
     * @return $this
     * @throws LocalizedException
     * @throws MissingFieldException
     * @throws \Exception
     */
    private function convertDataForCdCountry(Track $magentoTrack)
    {
        if (! $this->consignment->isCdCountry()) {
            return $this;
        }

        if ($products =
            $magentoTrack->getShipment()
                ->getData('items')) {
            foreach ($products as $product) {
                $myParcelProduct = (new MyParcelCustomsItem())
                    ->setDescription($product->getName())
                    ->setAmount($product->getQty())
                    ->setWeight($this->dataHelper->convertToGrams($product->getWeight()) ?: 1)
                    ->setItemValue($this->getCentsByPrice($product->getPrice()))
                    ->setClassification(
                        (int) $this->getAttributeValue(
                            'catalog_product_entity_int',
                            $product['product_id'],
                            'classification'
                        )
                    )
                    ->setCountry($this->getCountryOfOrigin($product['product_id']));
                $this->consignment->addItem($myParcelProduct);
            }
        }

        foreach ($magentoTrack->getShipment()
                     ->getItems() as $item) {
            $myParcelProduct = (new MyParcelCustomsItem())
                ->setDescription($item->getName())
                ->setAmount($item->getQty())
                ->setWeight($this->dataHelper->convertToGrams($item->getWeight() * $item->getQty()))
                ->setItemValue($item->getPrice() * 100)
                ->setClassification(
                    (int) $this->getAttributeValue(
                        'catalog_product_entity_int',
                        $item->getProductId(),
                        'classification'
                    )
                )
                ->setCountry($this->getCountryOfOrigin($item->getProductId()));

            $this->consignment->addItem($myParcelProduct);
        }

        return $this;
    }

    /**
     * @param  Order\Shipment\Track $magentoTrack
     * @param  object               $address
     * @param  array                $options
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function getAgeCheck(Track $magentoTrack, $address, array $options = []): bool
    {
        if ($address->getCountryId() !== AbstractConsignment::CC_NL) {
            return false;
        }

        $ageCheckFromOptions  = ShipmentOptions::getValueOfOptionWhenSet('age_check', $options);
        $ageCheckOfProduct    = ShipmentOptions::getAgeCheckFromProduct($magentoTrack);
        $ageCheckFromSettings = self::$defaultOptions->hasDefaultOptionsWithoutPrice($this->carrier, 'age_check');

        return $ageCheckFromOptions ?? $ageCheckOfProduct ?? $ageCheckFromSettings;
    }

    /**
     * @param  string $tableName
     * @param  string $entityId
     * @param  string $column
     *
     * @return string|null
     */
    private function getAttributeValue(string $tableName, string $entityId, string $column): ?string
    {
        $objectManager = ObjectManager::getInstance();
        $resource      = $objectManager->get(ResourceConnection::class);
        $connection    = $resource->getConnection();
        $attributeId   = ShipmentOptions::getAttributeId(
            $connection,
            $resource->getTableName('eav_attribute'),
            $column
        );

        return ShipmentOptions::getValueFromAttribute(
            $connection,
            $resource->getTableName($tableName),
            $attributeId,
            $entityId
        );
    }

    /**
     * @param  array $options
     *
     * @return null|string
     */
    private function getCarrierFromOptions(array $options): ?string
    {
        $carrier = null;

        if (array_key_exists('carrier', $options) && $options['carrier']) {
            $carrier =
                DefaultOptions::DEFAULT_OPTION_VALUE === $options['carrier'] ? self::$defaultOptions->getCarrier()
                    : $options['carrier'];
        }

        return $carrier;
    }

    /**
     * @param  array                $options
     * @param  string               $packageType
     * @param  Order\Shipment\Track $magentoTrack
     * @param  object               $address
     *
     * @return int
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function getPackageType(array $options, Track $magentoTrack, $address): int
    {
        if ($this->getAgeCheck($magentoTrack, $address, $options)) {
            return AbstractConsignment::PACKAGE_TYPE_PACKAGE;
        }

        // get package type from selected radio buttons and check if package type is set
        $packageType = $options['package_type'] ?? 'default';
        if ('default' === $packageType) {
            $packageType = self::$defaultOptions->getPackageType();
        }

        if (! is_numeric($packageType)) {
            $packageType = AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP[$packageType] ?? self::$defaultOptions->getPackageType();
        }

        return $packageType;
    }
}
