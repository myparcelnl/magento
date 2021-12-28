<?php
/**
 * An object with the track and trace data
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Sales;

use Exception;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Adapter\DeliveryOptionsFromOrderAdapter;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Services\Normalizer\ConsignmentNormalizer;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\AbstractShipmentOptionsAdapter;
use MyParcelNL\Sdk\src\Exception\MissingFieldException;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\MyParcelCustomsItem;

/**
 * Class TrackTraceHolder
 * @package MyParcelNL\Magento\Model\Sales
 */
class TrackTraceHolder
{
    /**
     * Track title showing in Magento
     */
    public const MYPARCEL_TRACK_TITLE   = 'MyParcel';
    public const MYPARCEL_CARRIER_CODE  = 'myparcel';
    public const EXPORT_MODE_PPS        = 'pps';
    public const EXPORT_MODE_SHIPMENTS  = 'shipments';

    private const ORDER_NUMBER          = '%order_nr%';
    private const DELIVERY_DATE         = '%delivery_date%';
    private const PRODUCT_ID            = '%product_id%';
    private const PRODUCT_NAME          = '%product_name%';
    private const PRODUCT_QTY           = '%product_qty%';

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

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
            ->setTrackNumber(TrackAndTrace::VALUE_EMPTY);

        return $this;
    }

    /**
     * Set all data to MyParcel object
     *
     * @param  Order\Shipment\Track  $magentoTrack
     * @param  array                 $options
     *
     * @return $this
     * @throws \Exception
     * @throws LocalizedException
     */
    public function convertDataFromMagentoToApi(Track $magentoTrack, array $options): self
    {
        $shipment        = $magentoTrack->getShipment();
        $address         = $shipment->getShippingAddress();
        $checkoutData    = $shipment->getOrder()->getData('myparcel_delivery_options');
        $deliveryOptions = json_decode($checkoutData, true);
        $totalWeight     = $options['digital_stamp_weight'] !== null ? (int) $options['digital_stamp_weight'] : (int) self::$defaultOptions->getDigitalStampDefaultWeight();

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

        $packageType = $this->getPackageType($options, $magentoTrack, $address);

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
            ->setCompany(self::$defaultOptions->getMaxCompanyName($address->getCompany()))
            ->setPerson($address->getName());

        try {
            $this->consignment
                ->setFullStreet($address->getData('street'))
                ->setPostalCode(preg_replace('/\s+/', '', $address->getPostcode()));
        } catch (\Exception $e) {
            $errorHuman = 'An error has occurred while validating order number ' . $shipment->getOrder()->getIncrementId() . '. Check address.';
            $this->messageManager->addErrorMessage($errorHuman . ' View log file for more information.');
            $this->objectManager->get('Psr\Log\LoggerInterface')->critical($errorHuman . '-' . $e);

            $this->helper->setOrderStatus($magentoTrack->getOrderId(), Order::STATE_NEW);
        }

        $isBE = AbstractConsignment::CC_BE === $address->getCountryId();

        $this->consignment
            ->setCity($address->getCity())
            ->setPhone($address->getTelephone())
            ->setEmail($address->getEmail())
            ->setLabelDescription($this->getLabelDescription($magentoTrack, $checkoutData))
            ->setDeliveryDate($this->helper->convertDeliveryDate($deliveryOptionsAdapter->getDate()))
            ->setDeliveryType($this->helper->checkDeliveryType($deliveryOptionsAdapter->getDeliveryTypeId()))
            ->setPackageType($packageType)
            ->setOnlyRecipient(! $isBE && $this->getValueOfOption($options, 'only_recipient'))
            ->setSignature(! $isBE && $this->getValueOfOption($options, 'signature'))
            ->setReturn(! $isBE && $this->getValueOfOption($options, 'return'))
            ->setLargeFormat($this->checkLargeFormat())
            ->setAgeCheck($this->getAgeCheck($magentoTrack, $address))
            ->setInsurance($options['insurance'] ?? self::$defaultOptions->getDefaultInsurance())
            ->setInvoice($magentoTrack->getShipment()->getOrder()->getIncrementId())
            ->setSaveRecipientAddress(false);

        if ($deliveryOptionsAdapter->isPickup()) {
            $this->consignment
                ->setPickupPostalCode($pickupLocationAdapter->getPostalCode())
                ->setPickupStreet($pickupLocationAdapter->getStreet())
                ->setPickupCity($pickupLocationAdapter->getCity())
                ->setPickupNumber($pickupLocationAdapter->getNumber())
                ->setPickupCountry($pickupLocationAdapter->getCountry())
                ->setPickupLocationName($pickupLocationAdapter->getLocationName())
                ->setPickupLocationCode($pickupLocationAdapter->getLocationCode());

            if ($isBE) {
                $this->consignment->setInsurance(null);
            }

            if ($pickupLocationAdapter->getRetailNetworkId()) {
                $this->consignment->setRetailNetworkId($pickupLocationAdapter->getRetailNetworkId());
            }
        }

        $this->convertDataForCdCountry($magentoTrack)
             ->calculateTotalWeight($magentoTrack, $totalWeight);

        return $this;
    }

    /**
     * @param  array                 $options
     * @param  Order\Shipment\Track  $magentoTrack
     * @param  object                $address
     *
     * @return int
     * @throws LocalizedException
     */
    private function getPackageType(array $options, Track $magentoTrack, $address): int
    {
        // get packagetype from delivery_options and use it for process directly
        $packageType = self::$defaultOptions->getPackageType();
        // get packagetype from selected radio buttons and check if package type is set
        if ($options['package_type'] && $options['package_type'] != 'default') {
            $packageType = $options['package_type'] ?? AbstractConsignment::PACKAGE_TYPE_PACKAGE;
        }

        if (! is_numeric($packageType)) {
            $packageType = AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP[$packageType];
        }

        return $this->getAgeCheck($magentoTrack, $address) ? AbstractConsignment::PACKAGE_TYPE_PACKAGE : $packageType;
    }

    /**
     * @return bool
     */
    private function checkLargeFormat(): bool
    {
        return self::$defaultOptions->getDefaultLargeFormat('large_format');
    }

    /**
     * @param  Order\Shipment\Track  $magentoTrack
     * @param  object                $address
     *
     * @return bool
     * @throws LocalizedException
     */
    private function getAgeCheck(Track $magentoTrack, $address): bool
    {
        if ($address->getCountryId() !== AbstractConsignment::CC_NL) {
            return false;
        }

        $ageCheckOfProduct    = $this->getAgeCheckFromProduct($magentoTrack);
        $ageCheckFromSettings = self::$defaultOptions->getDefaultOptionsWithoutPrice('age_check');

        return $ageCheckOfProduct ?? $ageCheckFromSettings;
    }

    /**
     * @param  Order\Shipment\Track  $magentoTrack
     *
     * @return bool
     * @throws LocalizedException
     */
    private function getAgeCheckFromProduct($magentoTrack): ?bool
    {
        $products    = $magentoTrack->getShipment()->getItems();
        $hasAgeCheck = false;

        foreach ($products as $product) {
            $productAgeCheck = $this->getAttributeValue('catalog_product_entity_varchar', $product['product_id'], 'age_check');

            if (! isset($productAgeCheck)) {
                $hasAgeCheck = null;
            } elseif ($productAgeCheck) {
                return true;
            }
        }

        return $hasAgeCheck;
    }

    /**
     * Override to check if key isset
     *
     * @param string $apiKey
     *
     * @return $this
     * @throws LocalizedException
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
     * @param string|null          $checkoutData
     *
     * @return string
     * @throws LocalizedException
     */
    public function getLabelDescription(Track $magentoTrack, ?string $checkoutData): string
    {
        $order = $magentoTrack->getShipment()->getOrder();

        $labelDescription = $this->helper->getGeneralConfig(
            'print/label_description',
            $order->getStoreId()
        );

        if (! $labelDescription) {
            return '';
        }

        $productInfo      = $this->getItemsCollectionByShipmentId($magentoTrack->getShipment()->getId());
        $deliveryDate     = date('d-m-Y', strtotime($this->helper->convertDeliveryDate($checkoutData)));
        $labelDescription = str_replace(
            [
                self::ORDER_NUMBER,
                self::DELIVERY_DATE,
                self::PRODUCT_ID,
                self::PRODUCT_NAME,
                self::PRODUCT_QTY
            ],
            [
                $order->getIncrementId(),
                $this->helper->convertDeliveryDate($checkoutData) ? $deliveryDate : '',
                $this->getProductInfo($productInfo, 'product_id'),
                $this->getProductInfo($productInfo, 'name'),
                round($this->getProductInfo($productInfo, 'qty'), 0),
            ],
            $labelDescription
        );

        return (string) $labelDescription;
    }

    /**
     * @param $productInfo
     * @param $field
     *
     * @return string|null
     */
    private function getProductInfo(array $productInfo, string $field): ?string
    {
        if ($productInfo) {
            return $productInfo[0][$field];
        }

        return null;
    }

    /**
     * @param Order\Shipment\Track $magentoTrack
     *
     * @return $this
     *
     * @throws LocalizedException
     * @throws MissingFieldException
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
                    ->setWeight($this->helper->getWeightTypeOfOption($product->getWeight()) ?: 1)
                    ->setItemValue($this->getCentsByPrice($product->getPrice()))
                    ->setClassification(
                        (int) $this->getAttributeValue('catalog_product_entity_int', $product['product_id'], 'classification')
                    )
                    ->setCountry($this->getCountryOfOrigin($product['product_id']));
                $this->consignment->addItem($myParcelProduct);
            }
        }

        $products = $this->getItemsCollectionByShipmentId($magentoTrack->getShipment()->getId());

        foreach ($magentoTrack->getShipment()->getItems() as $item) {
            $myParcelProduct = (new MyParcelCustomsItem())
                ->setDescription($item->getName())
                ->setAmount($item->getQty())
                ->setWeight($this->helper->getWeightTypeOfOption($item->getWeight() * $item->getQty()))
                ->setItemValue($item->getPrice() * 100)
                ->setClassification((int) $this->getAttributeValue('catalog_product_entity_int', $item->getProductId(), 'classification'))
                ->setCountry($this->getCountryOfOrigin($item->getProductId()));

            $this->consignment->addItem($myParcelProduct);
        }

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
        $product                     = $this->objectManager->get('Magento\Catalog\Api\ProductRepositoryInterface')->getById($product_id);
        $productCountryOfManufacture = $product->getCountryOfManufacture();

        if ($productCountryOfManufacture) {
            return $productCountryOfManufacture;
        }

        return $this->helper->getGeneralConfig('print/country_of_origin');
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
        $resource      = $objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection    = $resource->getConnection();
        $attributeId   = $this->getAttributeId(
            $connection,
            $resource->getTableName('eav_attribute'),
            $column
        );

        $attributeValue = $this
            ->getValueFromAttribute(
                $connection,
                $resource->getTableName($tableName),
                $attributeId,
                $entityId
            );

        return $attributeValue;
    }

    /**
     * @param object $connection
     * @param string $tableName
     * @param string $databaseColumn
     *
     * @return mixed
     */
    private function getAttributeId($connection, string $tableName, string $databaseColumn): string
    {
        $sql = $connection
            ->select('entity_type_id')
            ->from($tableName)
            ->where('attribute_code = ?', 'myparcel_' . $databaseColumn);

        return $connection->fetchOne($sql);
    }

    /**
     * @param object $connection
     * @param string $tableName
     *
     * @param string $attributeId
     * @param string $entityId
     *
     * @return string|null
     */
    private function getValueFromAttribute($connection, string $tableName, string $attributeId, string $entityId): ?string
    {
        $sql = $connection
            ->select()
            ->from($tableName, ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id = ?', $entityId);

        return $connection->fetchOne($sql);
    }

    /**
     * Get default value if option === null
     *
     * @param      $options []
     * @param      $optionKey
     *
     * @return bool
     * @internal param $option
     */
    private function getValueOfOption($options, $optionKey)
    {
        if ($options[$optionKey] === null) {
            return (bool) self::$defaultOptions->getDefault($optionKey);
        }

        return (bool) $options[$optionKey];
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
