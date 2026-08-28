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
use InvalidArgumentException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\LegacyInsuranceTiers;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Dating;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\ShipmentOptionsResolver;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\Exception\MissingFieldException;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\Model\MyParcelCustomsItem;

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
        $checkoutData               = $order->getData('myparcel_delivery_options') ?? '[]';
        $deliveryOptions            = $this->jsonSerializer->unserialize($checkoutData) ?? [];
        $checkoutCarrier            = $deliveryOptions['carrier'] ?? null;
        $selectedCarrier            = $this->getCarrierFromOptions($options) ?? $this->defaultOptions->getCarrierName();
        $deliveryOptions['carrier'] = $selectedCarrier;

        // A pickup location is carrier-specific (location code, retail network). If the carrier is
        // overridden (e.g. in a bulk action) to a different one than was chosen at checkout, the
        // inherited pickup location is no longer valid — ship as standard home delivery instead.
        if ($checkoutCarrier && $selectedCarrier !== $checkoutCarrier && ! empty($deliveryOptions['isPickup'])) {
            unset($deliveryOptions['pickupLocation']);
            $deliveryOptions['isPickup']     = false;
            $deliveryOptions['deliveryType'] = DeliveryType::STANDARD_NAME;
        }

        $apiKey = $this->config->getGeneralConfig('api/key', $order->getStoreId());
        if (empty($apiKey)) {
            throw new LocalizedException(
                __('API key is not known. Go to the settings in the backoffice to create an API key. Fill the API key in the settings.')
            );
        }

        try {
            // create new instance from known json
            $deliveryOptionsAdapter = DeliveryOptionsFactory::create((array) $deliveryOptions);
        } catch (BadMethodCallException | InvalidArgumentException $e) {
            // create new instance from unknown json data
            $deliveryOptionsAdapter = DeliveryOptions::fromOrderFallback((array) $deliveryOptions + $options);
        }

        $pickupLocationAdapter = $deliveryOptionsAdapter->getPickupLocation();
        $this->carrier         = $deliveryOptionsAdapter->getCarrier();
        $shipmentOptions       = (new ShipmentOptionsResolver(
            $this->defaultOptions,
            $order,
            $deliveryOptionsAdapter,
            $this->objectManager,
            $this->carrier,
            $options
        ))->resolve();

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
        $deliveryDate = (PackageType::PACKAGE_SMALL === $packageType
            && 'NL' !== $address->getCountryId()) ? null : Dating::convertDeliveryDate($deliveryOptionsAdapter->getDate());

        $regionCode = $address->getRegionCode();
        $state      = $regionCode && strlen($regionCode) === 2 ? $regionCode : null;

        $this->consignment
            ->setCity($address->getCity())
            ->setState($state)
            ->setPhone($address->getTelephone())
            ->setEmail($address->getEmail())
            ->setLabelDescription($shipmentOptions->getLabelDescription() ?? '')
            ->setDeliveryType($deliveryOptionsAdapter->getDeliveryTypeId() ?? DeliveryType::STANDARD)
            ->setDeliveryDate($deliveryDate)
            ->setPackageType($packageType)
            // until capabilities: set receipt code first because it blocks other options
            ->setReceiptCode($shipmentOptions->hasReceiptCode() ?? false)
            ->setOnlyRecipient($shipmentOptions->hasOnlyRecipient() ?? false)
            ->setSignature($shipmentOptions->hasSignature() ?? false)
            ->setCollect($shipmentOptions->hasCollect() ?? false)
            ->setReturn($shipmentOptions->hasReturn() ?? false)
            ->setSameDayDelivery($shipmentOptions->hasSameDayDelivery() ?? false)
            ->setLargeFormat($shipmentOptions->hasLargeFormat() ?? false)
            ->setAgeCheck($shipmentOptions->hasAgeCheck() ?? false)
            ->setPriorityDelivery($shipmentOptions->hasPriorityDelivery() ?? false)
            ->setInsurance($this->insuranceAcceptedByTheSdk($shipmentOptions->getInsurance(), $address->getCountryId()))
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
        if ($packageType === PackageType::DIGITAL_STAMP) {
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
        if ($address->getCountryId() !== CountryCode::CC_NL) {
            return false;
        }

        $ageCheckFromOptions  = $options[ShipmentOption::AGE_CHECK] ?? null;
        $ageCheckOfProduct    = ShipmentOptionsResolver::getAgeCheckFromProduct($magentoTrack);
        $ageCheckFromSettings = $this->defaultOptions->hasDefaultOption($this->carrier, ShipmentOption::AGE_CHECK);

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
        $attributeId   = ShipmentOptionsResolver::getAttributeId(
            $connection,
            $resource->getTableName('eav_attribute'),
            $column
        );

        return ShipmentOptionsResolver::getValueFromAttribute(
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
    /**
     * @todo Phase 6 deletes this with the consignment path (DR-20).
     *
     * beta.15's AbstractConsignment::setInsurance() throws for a domestic amount that is not one of
     * the carrier's hardcoded tiers, and ConsignmentEncode uses the same list as a floor. Insurance
     * is a free amount from Phase 5 on, so without this the branch cannot export a domestic order
     * between Phases 5 and 6.
     *
     * It substitutes silently, which DR-12 forbids — recorded there as a known, temporary cost on an
     * unreleased branch, not as the intended behaviour.
     */
    private function insuranceAcceptedByTheSdk(int $insurance, ?string $countryCode): int
    {
        if (0 === $insurance) {
            return 0;
        }

        $accepted = LegacyInsuranceTiers::acceptableForSdk(
            $this->carrier,
            LegacyInsuranceTiers::zoneFor($countryCode),
            $insurance
        );

        if ($accepted !== $insurance) {
            Logger::notice(sprintf(
                'Insurance %d sent as %d: the pinned SDK accepts only its own tiers (DR-20).',
                $insurance,
                $accepted
            ));
        }

        return $accepted;
    }

    private function getPackageType(Track $magentoTrack, $address, array $options, array $deliveryOptions): int
    {
        if ($this->getAgeCheck($magentoTrack, $address, $options)) {
            return PackageType::PACKAGE;
        }

        // get package type from selected radio buttons, try to get from delivery options when default or not set
        $packageType = $options['package_type'] ?? 'default';
        if ('default' === $packageType) {
            $packageType = $deliveryOptions['packageType'] ?? $this->defaultOptions->getPackageType();
        }

        if (! is_numeric($packageType)) {
            $packageType = PackageType::NAMES_IDS_MAP[$packageType] ?? $this->defaultOptions->getPackageType();
        }

        return $packageType;
    }
}
