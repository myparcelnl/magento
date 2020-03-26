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

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Tests\NamingConvention\true\mixed;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\Consignment\PostNLConsignment;
use MyParcelNL\Sdk\src\Model\MyParcelCustomsItem;

/**
 * Class MyParcelTrackTrace
 * @package MyParcelNL\Magento\Model\Sales
 */
class TrackTraceHolder
{
    /**
     * Track title showing in Magento
     */
    const MYPARCEL_TRACK_TITLE  = 'MyParcel';
    const MYPARCEL_CARRIER_CODE = 'myparcelnl';
    const ORDER_NUMBER          = '%order_nr%';
    const DELIVERY_DATE         = '%delivery_date%';
    const PRODUCT_ID            = '%product_id%';
    const PRODUCT_NAME          = '%product_name%';
    const PRODUCT_QTY           = '%product_qty%';

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
     * MyParcelTrackTrace constructor.
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
        $this->consignment = ConsignmentFactory::createByCarrierId(PostNLConsignment::CARRIER_ID);

        $address      = $magentoTrack->getShipment()->getShippingAddress();
        $checkoutData = $magentoTrack->getShipment()->getOrder()->getData('delivery_options');
        $deliveryType = $this->consignment->getDeliveryTypeFromCheckout($checkoutData);
        $totalWeight  = $options['digital_stamp_weight'] !== null ? (int) $options['digital_stamp_weight'] : (int) self::$defaultOptions->getDigitalStampWeight();

        if ($options['package_type'] === 'default') {
            $packageType = self::$defaultOptions->getPackageType();
        } else {
            $packageType = (int) $options['package_type'] ?: 1;
        }

        if ($address->getCountryId() != 'NL' &&
            ((int) $options['package_type'] == 2 || (int) $options['package_type'] == 4)) {
            $options['package_type'] = 1;
        }

        $apiKey = $this->helper->getGeneralConfig(
            'api/key',
            $magentoTrack->getShipment()->getOrder()->getStoreId()
        );

        $this->validateApiKey($apiKey);

        $this->consignment = (ConsignmentFactory::createByCarrierId(PostNLConsignment::CARRIER_ID))
            ->setApiKey($apiKey)
            ->setReferenceId($magentoTrack->getShipment()->getEntityId())
            ->setConsignmentId($magentoTrack->getData('myparcel_consignment_id'))
            ->setCountry($address->getCountryId())
            ->setCompany(self::$defaultOptions->getMaxCompanyName($address->getCompany()))
            ->setPerson($address->getName());

        try {
            $this->consignment->setFullStreet($address->getData('street'));
        } catch (\Exception $e) {
            $errorHuman = 'An error has occurred while validating the address: ' . $address->getData('street') . '. Check number and number suffix.';
            $this->messageManager->addErrorMessage($errorHuman . ' View log file for more information.');
            $this->objectManager->get('Psr\Log\LoggerInterface')->critical($errorHuman . '-' . $e);
        }

        if ($address->getPostcode() == null && $address->getCountryId() == 'NL') {
            $errorHuman = 'An error has occurred while validating the order number ' . $magentoTrack->getOrderId() . '. Postcode is required.';
            $this->messageManager->addErrorMessage($errorHuman . ' View log file for more information.');
            $this->objectManager->get('Psr\Log\LoggerInterface')->critical($errorHuman);
        }

        $this->consignment
            ->setPostalCode($address->getPostcode())
            ->setCity($address->getCity())
            ->setPhone($address->getTelephone())
            ->setEmail($address->getEmail())
            ->setLabelDescription($this->getLabelDescription($magentoTrack, $checkoutData))
            ->setDeliveryDate($this->convertDeliveryDate($checkoutData))
            ->setDeliveryType($deliveryType)
            ->setPickupAddressFromCheckout($checkoutData)
            ->setPackageType($packageType)
            ->setOnlyRecipient($this->getValueOfOption($options, 'only_recipient'))
            ->setSignature($this->getValueOfOption($options, 'signature'))
            ->setReturn($this->getValueOfOption($options, 'return'))
            ->setLargeFormat($this->getValueOfOption($options, 'large_format'))
            ->setAgeCheck($this->getValueOfOption($options, 'age_check'))
            ->setInsurance(
                $options['insurance'] !== null ? $options['insurance'] : self::$defaultOptions->getDefaultInsurance()
            )
            ->setInvoice($magentoTrack->getShipment()->getOrder()->getIncrementId());

        $this->convertDataForCdCountry($magentoTrack)
             ->calculateTotalWeight($magentoTrack, $totalWeight);

        return $this;
    }

    /**
     * Convert delivery date to tomorrow, when delivery date is passed
     *
     * @param string|null $checkoutData
     *
     * @return string
     */
    public function convertDeliveryDate(?string $checkoutData): string
    {
        $deliveryDetails = json_decode($checkoutData, true);

        $deliveryDate = strtotime($deliveryDetails['date'] ?? 'now');
        $todayDate    = strtotime('now');

        if ($deliveryDate <= $todayDate) {
            $deliveryDetails['date'] = date('Y-m-d', strtotime('+1 day'));
        }

        return $deliveryDetails['date'];
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
     * @param string|null          $checkoutData
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getLabelDescription($magentoTrack, ?string $checkoutData): string
    {
        $order = $magentoTrack->getShipment()->getOrder();

        $labelDescription = $this->helper->getGeneralConfig(
            'basic_settings/label_description',
            $order->getStoreId()
        );

        if (! $labelDescription) {
            return '';
        }

        $productInfo      = $this->getItemsCollectionByShipmentId($magentoTrack->getShipment()->getId());
        $deliveryDate     = date('d-m-Y', strtotime($this->convertDeliveryDate($checkoutData)));

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
                $deliveryDate,
                $productInfo[0]['product_id'],
                $productInfo[0]['name'],
                (int) $productInfo[0]['qty']
            ],
            $labelDescription);

        return $labelDescription;
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

        $products = $this->getItemsCollectionByShipmentId($magentoTrack->getShipment()->getId());
        foreach ($products as $product) {
            $myParcelProduct = (new MyParcelCustomsItem())
                ->setDescription($product['name'])
                ->setAmount($product['qty'])
                ->setWeight($this->getWeightTypeOfOption($product['weight']))
                ->setItemValue($product['price'] * 100)
                ->setClassification((int) $this->getAttributeValue('catalog_product_entity_int', $product['product_id'], 'classification'))
                ->setCountry((string) $this->getCountryOfOrigin($product));

            $this->consignment->addItem($myParcelProduct);
        }
        return $this;
    }

    /**
     * Get the correct weight type
     *
     * @param string|null $weight
     *
     * @return int
     */
    private function getWeightTypeOfOption(?string $weight): int
    {
        $weightType = $this->helper->getGeneralConfig(
            'basic_settings/weight_indication'
        );

        if ($weightType != 'gram') {
            return (int) ($weight * 1000);
        }

        return (int) $weight ?: 1000;
    }

    /**
     * Get country of origin from product settings or, if they are not found, from the MyParcel settings.
     *
     * @param $product
     * @return string
     */
    public function getCountryOfOrigin($product): string
    {
        $productCountryOfOrigin = (string) $this->getAttributeValue('catalog_product_entity_varchar', $product['product_id'], 'country_of_manufacture', true);
        var_dump($productCountryOfOrigin);
        exit();
        $mpCountryOfOrigin = $this->helper->getGeneralConfig('basic_settings/country_of_origin');

        if ($productCountryOfOrigin) {
            return $productCountryOfOrigin;
        }

        return $mpCountryOfOrigin;
    }

    /**
     * @param string $tableName
     * @param string $entityId
     * @param string $column
     * @param bool $isMagentoAttr
     *
     * @return string|null
     */
    private function getAttributeValue(string $tableName, string $entityId, string $column, bool $isMagentoAttr = null): ?string
    {
        $objectManager  = \Magento\Framework\App\ObjectManager::getInstance();
        $resource       = $objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection     = $resource->getConnection();
        if (! $isMagentoAttr) {
            $attributeId    = $this->getAttributeId(
                $connection,
                $resource->getTableName('eav_attribute'),
                $column
            );
        } else {
            $attributeId    = $this->getMagentoAttributeId(
                $connection,
                $resource->getTableName('eav_attribute'),
                $column
            );
        }

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
     * @param object $connection
     * @param string $tableName
     * @param string $databaseColumn
     *
     * @return mixed
     */
    private function getMagentoAttributeId(object $connection, string $tableName, string $databaseColumn): string
    {
        $sql = $connection
            ->select('entity_type_id')
            ->from($tableName)
            ->where('attribute_code = ' . $databaseColumn);

        return $connection->fetchOne($sql);
    }

    /**
     * @param object $connection
     * @param string $tableName
     * @param string $databaseColumn
     *
     * @return mixed
     */
    private function getAttributeId(object $connection, string $tableName, string $databaseColumn): string
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
    private function getValueFromAttribute(object $connection, string $tableName, string $attributeId, string $entityId): ?string
    {
        $sql = $connection
            ->select()
            ->from($tableName, ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id = ?', $entityId);

        return $connection->fetchOne($sql);
    }

    /**
     * @param Order\Shipment\Track $magentoTrack
     * @param int                  $totalWeight
     *
     * @return TrackTraceHolder
     * @throws LocalizedException
     * @throws \Exception
     */
    private function calculateTotalWeight($magentoTrack, $totalWeight = 0)
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

        if ($magentoTrack->getShipment()->getData('items') != null) {
            $products = $magentoTrack->getShipment()->getData('items');

            foreach ($products as $product) {
                $totalWeight += $product->consignment->getWeight();
            }
        }

        $products = $this->getItemsCollectionByShipmentId($magentoTrack->getShipment()->getId());

        foreach ($products as $product) {
            $totalWeight += $product['weight'];
        }

        if ($totalWeight == 0) {
            throw new \Exception('The order with digital stamp can not be exported, no weights have been entered');
        }

        $this->consignment->setPhysicalProperties(
            [
                "weight" => $totalWeight
            ]
        );

        return $this;
    }
}
