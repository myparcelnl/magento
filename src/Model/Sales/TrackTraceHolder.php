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

namespace MyParcelNL\Magento\Model\Sales;

use BadMethodCallException;
use Exception;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Adapter\DeliveryOptionsFromOrderAdapter;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Helper\ShipmentOptions;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Dating;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\Exception\MissingFieldException;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\Model\MyParcelCustomsItem;
use RuntimeException;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Class TrackTraceHolder
 *
 * @package MyParcelNL\Magento\Model\Sales
 */
class TrackTraceHolder
{
    private DefaultOptions         $defaultOptions;
    public ?AbstractConsignment    $consignment;
    public Track                   $mageTrack;
    protected ManagerInterface     $messageManager;
    private ?string                $carrier;
    private Config                 $config;
    private ObjectManagerInterface $objectManager;
    private Weight                 $weight;
    private JsonSerializer         $jsonSerializer;

    /**
     * TrackTraceHolder constructor.
     *
     * @param ObjectManagerInterface $objectManager
     * @param Order                  $order
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        Order                  $order
    )
    {
        $this->objectManager  = $objectManager;
        $this->config         = $objectManager->get(Config::class);
        $this->weight         = $objectManager->get(Weight::class);
        $this->jsonSerializer = $objectManager->get(JsonSerializer::class);
        $this->messageManager = $this->objectManager->create('Magento\Framework\Message\ManagerInterface');
        $this->defaultOptions = new DefaultOptions($order);
    }

    /**
     * Set all data to MyParcel object
     *
     * @param Order\Shipment\Track $magentoTrack
     * @param array                $options
     *
     * @return self
     * @throws Exception
     * @throws LocalizedException
     */
    public function convertDataFromMagentoToApi(Track $magentoTrack, array $options): self
    {
        $shipment = $magentoTrack->getShipment();
        if (null === $shipment) {
            Logger::warning('Shipment not found', ['track' => $magentoTrack->getData()]);
            return $this;
        }

        $address                    = $shipment->getShippingAddress();
        $order                      = $shipment->getOrder();
        $checkoutData               = $order->getData('myparcel_delivery_options') ?? '';
        $deliveryOptions            = $this->jsonSerializer->unserialize($checkoutData) ?? [];
        $deliveryOptions['carrier'] = $this->defaultOptions->getCarrierName();

        $apiKey = $this->config->getGeneralConfig('api/key', $order->getStoreId());
        if (empty($apiKey)) {
            throw new LocalizedException(
                __('API key is not known. Go to the settings in the backoffice to create an API key. Fill the API key in the settings.')
            );
        }

        try {
            // create new instance from known json
            $deliveryOptionsAdapter = DeliveryOptionsAdapterFactory::create((array) $deliveryOptions);
        } catch (BadMethodCallException $e) {
            // create new instance from unknown json data
            $deliveryOptionsAdapter = new DeliveryOptionsFromOrderAdapter((array) $deliveryOptions + $options);
        }

        $pickupLocationAdapter = $deliveryOptionsAdapter->getPickupLocation();
        $this->carrier         = $deliveryOptionsAdapter->getCarrier();
        $shipmentOptions       = new ShipmentOptions(
            $this->defaultOptions,
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
            ->setCompany($address->getCompany())
            ->setPerson($address->getName())
        ;

        try {
            $this->consignment
                ->setFullStreet($address->getData('street'))
                ->setPostalCode(preg_replace('/\s+/', '', $address->getPostcode()))
            ;
        } catch (\Throwable $e) {
            $errorHuman
                = sprintf(
                'An error has occurred while validating order number %s. Check address.',
                $order->getIncrementId()
            );
            $this->messageManager->addErrorMessage($errorHuman . ' View log file for more information.');
            $this->objectManager->get('Psr\Log\LoggerInterface')
                                ->critical($errorHuman . '-' . $e)
            ;

            $this->setOrderStatus($magentoTrack->getOrderId(), Order::STATE_NEW);
        }

        $packageType  = $this->getPackageType($magentoTrack, $address, $options, $deliveryOptions);
        $deliveryDate = (AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL === $packageType
            && 'NL' !== $address->getCountryId()) ? null : Dating::convertDeliveryDate($deliveryOptionsAdapter->getDate());

        $regionCode = $address->getRegionCode();
        $state      = $regionCode && strlen($regionCode) === 2 ? $regionCode : null;

        $this->consignment
            ->setCity($address->getCity())
            ->setState($state)
            ->setPhone($address->getTelephone())
            ->setEmail($address->getEmail())
            ->setLabelDescription($shipmentOptions->getLabelDescription())
            ->setDeliveryType($deliveryOptionsAdapter->getDeliveryTypeId() ?? AbstractConsignment::DELIVERY_TYPE_STANDARD)
            ->setDeliveryDate($deliveryDate)
            ->setPackageType($packageType)
            // until capabilities: set receipt code first because it blocks other options
            ->setReceiptCode($shipmentOptions->hasReceiptCode())
            ->setOnlyRecipient($shipmentOptions->hasOnlyRecipient())
            ->setSignature($shipmentOptions->hasSignature())
            ->setCollect($shipmentOptions->hasCollect())
            ->setReturn($shipmentOptions->hasReturn())
            ->setSameDayDelivery($shipmentOptions->hasSameDayDelivery())
            ->setLargeFormat($shipmentOptions->hasLargeFormat())
            ->setAgeCheck($shipmentOptions->hasAgeCheck())
            ->setPriorityDelivery($shipmentOptions->hasPriorityDelivery())
            ->setInsurance($shipmentOptions->getInsurance())
            ->setInvoice(
                $shipment
                    ->getOrder()
                    ->getIncrementId()
            )
            ->setSaveRecipientAddress(false)
        ;

        if ($pickupLocationAdapter && $deliveryOptionsAdapter->isPickup()) {
            $this->consignment
                ->setPickupPostalCode($pickupLocationAdapter->getPostalCode())
                ->setPickupStreet($pickupLocationAdapter->getStreet())
                ->setPickupCity($pickupLocationAdapter->getCity())
                ->setPickupNumber($pickupLocationAdapter->getNumber())
                ->setPickupCountry($pickupLocationAdapter->getCountry())
                ->setPickupLocationName($pickupLocationAdapter->getLocationName())
                ->setPickupLocationCode($pickupLocationAdapter->getLocationCode())
                ->setReturn(false)
            ;

            if ($pickupLocationAdapter->getRetailNetworkId()) {
                $this->consignment->setRetailNetworkId($pickupLocationAdapter->getRetailNetworkId());
            }
        }

        $weight = 0;
        if ($packageType === AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP) {
            // NOTE: digital stamp weight is always managed in grams regardless of weight settings, can still be 0 after this
            $weight = (int) ($options['digital_stamp_weight'] ?? $this->defaultOptions->getDigitalStampDefaultWeight());
        }

        try {
            $this->convertDataForCdCountry($magentoTrack)
                 ->calculateTotalWeight($magentoTrack, $weight, $packageType)
            ;
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this;
        }

        return $this;
    }

    /**
     * Create Magento Track from Magento shipment
     *
     * @param Shipment $shipment
     *
     * @return self
     */
    public function createTrackTraceFromShipment(Shipment $shipment): self
    {
        $this->mageTrack = $this->objectManager->create(Track::class);
        $this->mageTrack
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(Carrier::CODE)
            ->setTitle(Config::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber(TrackAndTrace::VALUE_EMPTY)
        ;

        return $this;
    }

    /**
     * @param int    $orderId
     * @param string $status
     */
    private function setOrderStatus(int $orderId, string $status): void
    {
        $order = ObjectManager::getInstance()
                              ->create('\Magento\Sales\Model\Order')
                              ->load($orderId)
        ;
        $order->setState($status)
              ->setStatus($status)
        ;
        $order->save();
    }

    /**
     * Get country of origin from product settings or, if they are not found, from the MyParcel settings.
     *
     * @param int $productId
     *
     * @return string
     */
    public function getCountryOfOrigin(int $productId): string
    {
        $product = $this->objectManager->get(ProductRepositoryInterface::class)
                                       ->getById($productId)
        ;

        $productCountryOfManufacture = $product->getCountryOfManufacture();

        if ($productCountryOfManufacture) {
            return $productCountryOfManufacture;
        }

        return $this->config->getGeneralConfig('print/country_of_origin');
    }

    /**
     * @param Track $magentoTrack
     * @param int   $presetWeightInGrams supply a weight in grams to use instead of calculating
     * @param int   $packageType
     * @return void
     * @throws LocalizedException
     */
    private function calculateTotalWeight(Track $magentoTrack, int $presetWeightInGrams, int $packageType): self
    {
        if (0 < $presetWeightInGrams) {
            $this->consignment->setPhysicalProperties(['weight' => $presetWeightInGrams]);

            return $this;
        }

        $shipmentItems = $magentoTrack->getShipment()->getItems();

        $weight = 0;
        foreach ($shipmentItems as $shipmentItem) {
            $weight += (float) $shipmentItem['weight'] * (float) $shipmentItem['qty'];
        }
        $weight = $this->weight->convertToGrams($weight) + $this->weight->getEmptyPackageWeightInGrams($packageType);

        $this->consignment->setPhysicalProperties(['weight' => $weight]);

        return $this;
    }

    /**
     * @param Order\Shipment\Track $magentoTrack
     *
     * @return self
     * @throws LocalizedException
     * @throws MissingFieldException
     * @throws Exception
     */
    private function convertDataForCdCountry(Track $magentoTrack): self
    {
        if (! $this->consignment->isToRowCountry()) {
            return $this;
        }

        if ($magentoTrack->getShipment()
            && ($products = $magentoTrack->getShipment()
                                         ->getData('items'))) {
            foreach ($products as $product) {
                $myParcelProduct = (new MyParcelCustomsItem())
                    ->setDescription($product->getName())
                    ->setAmount($product->getQty())
                    ->setWeight($this->weight->convertToGrams($product->getWeight()) ?: 1)
                    ->setItemValue(DeliveryCosts::getPriceInCents($product->getPrice()))
                    ->setClassification(
                        (int) $this->getAttributeValue(
                            'catalog_product_entity_int',
                            $product['product_id'],
                            'classification'
                        )
                    )
                    ->setCountry($this->getCountryOfOrigin($product['product_id']))
                ;
                $this->consignment->addItem($myParcelProduct);
            }
        }

        foreach ($magentoTrack->getShipment()
                              ->getItems() as $item) {
            $myParcelProduct = (new MyParcelCustomsItem())
                ->setDescription($item->getName())
                ->setAmount($item->getQty())
                ->setWeight($this->weight->convertToGrams($item->getWeight() * $item->getQty()))
                ->setItemValue($item->getPrice() * 100)
                ->setClassification(
                    (int) $this->getAttributeValue(
                        'catalog_product_entity_int',
                        $item->getProductId(),
                        'classification'
                    )
                )
                ->setCountry($this->getCountryOfOrigin($item->getProductId()))
            ;

            $this->consignment->addItem($myParcelProduct);
        }

        return $this;
    }

    /**
     * @param Order\Shipment\Track $magentoTrack
     * @param object               $address
     * @param array                $options
     *
     * @return bool
     * @throws LocalizedException
     */
    private function getAgeCheck(Track $magentoTrack, $address, array $options = []): bool
    {
        if ($address->getCountryId() !== AbstractConsignment::CC_NL) {
            return false;
        }

        $ageCheckFromOptions  = $options[ShipmentOptions::AGE_CHECK] ?? null;
        $ageCheckOfProduct    = ShipmentOptions::getAgeCheckFromProduct($magentoTrack);
        $ageCheckFromSettings = $this->defaultOptions->hasDefaultOption($this->carrier, ShipmentOptions::AGE_CHECK);

        return $ageCheckFromOptions ?? $ageCheckOfProduct ?? $ageCheckFromSettings;
    }

    /**
     * @param string $tableName
     * @param string $entityId
     * @param string $column
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
     * @param array $options
     *
     * @return null|string
     */
    private function getCarrierFromOptions(array $options): ?string
    {
        $carrier = null;

        if (array_key_exists('carrier', $options) && $options['carrier']) {
            $carrier
                = DefaultOptions::DEFAULT_OPTION_VALUE === $options['carrier'] ? $this->defaultOptions->getCarrierName()
                : $options['carrier'];
        }

        return $carrier;
    }

    /**
     * @param Track  $magentoTrack
     * @param object $address
     *
     * @param array  $options
     * @param array  $deliveryOptions
     * @return int
     * @throws LocalizedException
     */
    private function getPackageType(Track $magentoTrack, $address, array $options, array $deliveryOptions): int
    {
        if ($this->getAgeCheck($magentoTrack, $address, $options)) {
            return AbstractConsignment::PACKAGE_TYPE_PACKAGE;
        }

        // get package type from selected radio buttons, try to get from delivery options when default or not set
        $packageType = $options['package_type'] ?? 'default';
        if ('default' === $packageType) {
            $packageType = $deliveryOptions['packageType'] ?? $this->defaultOptions->getPackageType();
        }

        if (! is_numeric($packageType)) {
            $packageType = AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP[$packageType] ?? $this->defaultOptions->getPackageType();
        }

        return $packageType;
    }
}
