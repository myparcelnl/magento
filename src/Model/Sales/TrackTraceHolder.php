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
        $this->objectManager = $objectManager;
        $this->config        = $objectManager->get(Config::class);
        $this->weight        = $objectManager->get(Weight::class);
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
        $deliveryOptions            = json_decode($checkoutData, true) ?? [];
        $deliveryOptions['carrier'] = $this->defaultOptions->getCarrierName();

        $totalWeight = $options['digital_stamp_weight'] !== null ? (int) $options['digital_stamp_weight']
            : (int) $this->defaultOptions->getDigitalStampDefaultWeight();

        try {
            // create new instance from known json
            $deliveryOptionsAdapter = DeliveryOptionsAdapterFactory::create((array) $deliveryOptions);
        } catch (BadMethodCallException $e) {
            // create new instance from unknown json data
            $deliveryOptionsAdapter = new DeliveryOptionsFromOrderAdapter((array) $deliveryOptions + $options);
        }

        $pickupLocationAdapter = $deliveryOptionsAdapter->getPickupLocation();
        $apiKey                = $this->config->getGeneralConfig(
            'api/key',
            $order->getStoreId()
        );

        $this->validateApiKey($apiKey);
        $this->carrier   = $deliveryOptionsAdapter->getCarrier();
        $shipmentOptions = new ShipmentOptions(
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
        } catch (Throwable $e) {
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
        $dropOffPoint = (new AccountSettings($apiKey))->getDropOffPoint(
            CarrierFactory::createFromName($deliveryOptionsAdapter->getCarrier())
        );

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
            ->setDropOffPoint($dropOffPoint)
            ->setOnlyRecipient($shipmentOptions->hasOnlyRecipient())
            ->setSignature($shipmentOptions->hasSignature())
            ->setCollect($shipmentOptions->hasCollect())
            ->setReceiptCode($shipmentOptions->hasReceiptCode())
            ->setReturn($shipmentOptions->hasReturn())
            ->setSameDayDelivery($shipmentOptions->hasSameDayDelivery())
            ->setLargeFormat($shipmentOptions->hasLargeFormat())
            ->setAgeCheck($shipmentOptions->hasAgeCheck())
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

        try {
            $this->convertDataForCdCountry($magentoTrack)
                 ->calculateTotalWeight($magentoTrack, $totalWeight)
            ;
        } catch (Throwable $e) {
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
    public function createTrackTraceFromShipment(Shipment $shipment)
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
     * Override to check if key isset
     *
     * @param null|string $apiKey
     *
     * @return self
     * @throws LocalizedException
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
     * @param Order\Shipment\Track $magentoTrack
     * @param int                  $totalWeight
     *
     * @return TrackTraceHolder
     * @throws LocalizedException
     * @throws Exception
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

        $weightFromSettings = (int) $this->defaultOptions->getDigitalStampDefaultWeight();
        if ($weightFromSettings) {
            $this->consignment->setPhysicalProperties(["weight" => $weightFromSettings]);

            return $this;
        }

        $shipmentItems
            = $magentoTrack->getShipment()
                           ->getItems()
        ;

        foreach ($shipmentItems as $shipmentItem) {
            $totalWeight += $shipmentItem['weight'] * $shipmentItem['qty'];
        }

        $totalWeight = $this->weight->convertToGrams($totalWeight);

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

        $ageCheckFromOptions  = ShipmentOptions::getValueOfOptionWhenSet(ShipmentOptions::AGE_CHECK, $options);
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
