<?php

namespace MyParcelNL\Magento\Helper;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Dating;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

class ShipmentOptions
{
    public const  INSURANCE         = ShipmentOption::INSURANCE;
    public const  ONLY_RECIPIENT    = ShipmentOption::ONLY_RECIPIENT;
    private const SAME_DAY_DELIVERY = ShipmentOption::SAME_DAY_DELIVERY;
    public const  SIGNATURE         = ShipmentOption::SIGNATURE;
    public const  COLLECT           = ShipmentOption::COLLECT;
    public const  RECEIPT_CODE      = ShipmentOption::RECEIPT_CODE;
    public const  RETURN            = ShipmentOption::RETURN;
    public const  AGE_CHECK         = ShipmentOption::AGE_CHECK;
    public const  LARGE_FORMAT      = ShipmentOption::LARGE_FORMAT;
    private const HIDE_SENDER       = ShipmentOption::HIDE_SENDER;
    public const  PRIORITY_DELIVERY = ShipmentOption::PRIORITY_DELIVERY;
    private const LABEL_DESCRIPTION = 'label_description';
    private const ORDER_NUMBER      = '%order_nr%';
    private const DELIVERY_DATE     = '%delivery_date%';
    private const PRODUCT_ID        = '%product_id%';
    private const PRODUCT_NAME      = '%product_name%';
    private const PRODUCT_QTY       = '%product_qty%';

    /**
     * @var string
     */
    private $carrier;

    /**
     * @var DefaultOptions
     */
    private $defaultOptions;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var array
     */
    private $options;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Order
     */
    private $order;

    /**
     * @var string|null
     */
    private ?string $cc;

    /**
     * @param DefaultOptions         $defaultOptions
     * @param Order                  $order
     * @param ObjectManagerInterface $objectManager
     * @param string                 $carrier
     * @param array                  $options
     */
    public function __construct(
        DefaultOptions         $defaultOptions,
        Order                  $order,
        ObjectManagerInterface $objectManager,
        string                 $carrier,
        array                  $options = []
    )
    {
        $this->defaultOptions = $defaultOptions;
        $this->config         = $objectManager->get(Config::class);
        $this->order          = $order;
        $this->objectManager  = $objectManager;
        $this->carrier        = $carrier;
        $this->options        = $options;
        $this->cc             = $order->getShippingAddress() ? $order->getShippingAddress()->getCountryId() : null;
    }

    /**
     * @return int
     */
    public function getInsurance(): int
    {
        return $this->options['insurance'] ?? $this->defaultOptions->getDefaultInsurance($this->carrier);
    }

    /**
     * @return bool
     */
    public function hasSignature(): bool
    {
        if (CountryCode::CC_BE === $this->cc && $this->hasOnlyRecipient()) {
            return false;
        }

        return $this->optionIsEnabled(self::SIGNATURE);
    }

    public function hasCollect(): bool
    {
        return $this->optionIsEnabled(self::COLLECT);
    }

    public function hasReceiptCode(): bool
    {
        $deliveryOptions = $this->order->getData(Config::FIELD_DELIVERY_OPTIONS) ?? [];
        $deliveryType    = $deliveryOptions['deliveryType'] ?? DeliveryType::DEFAULT;

        if (CountryCode::CC_NL !== $this->cc
            || CarrierPostNL::NAME !== $this->carrier
            || DeliveryType::STANDARD !== $deliveryType
        ) {
            return false;
        }

        return $this->optionIsEnabled(self::RECEIPT_CODE);
    }

    /**
     * @return bool
     */
    public function hasOnlyRecipient(): bool
    {
        return $this->optionIsEnabled(self::ONLY_RECIPIENT);
    }

    /**
     * @return bool
     */
    public function hasSameDayDelivery(): bool
    {
        return $this->optionIsEnabled(self::SAME_DAY_DELIVERY);
    }

    /**
     * @return bool
     */
    public function hasReturn(): bool
    {
        return $this->optionIsEnabled(self::RETURN);
    }

    /**
     * @return bool
     */
    public function hasAgeCheck(): bool
    {
        if (CountryCode::CC_NL !== $this->cc) {
            return false;
        }

        $ageCheckFromOptions  = $this->options[self::AGE_CHECK] ?? null;
        $ageCheckOfProduct    = self::getAgeCheckFromProduct($this->order->getItems());
        $ageCheckFromSettings = $this->defaultOptions->hasDefaultOption($this->carrier, self::AGE_CHECK);

        return $ageCheckFromOptions ?? $ageCheckOfProduct ?? $ageCheckFromSettings;
    }

    public function hasHideSender(): bool
    {
        return $this->optionIsEnabled(self::HIDE_SENDER);
    }

    /**
     * The myparcel_priority_delivery product attribute only controls checkout
     * visibility (allowPriorityDelivery); it never sets the shipment option
     * itself. Priority delivery is only enabled by an explicit choice.
     *
     * @return bool
     */
    public function hasPriorityDelivery(): bool
    {
        if (CountryCode::CC_NL !== $this->cc) {
            return false;
        }

        if (CarrierPostNL::NAME !== $this->carrier) {
            return false;
        }

        return $this->optionIsEnabled(self::PRIORITY_DELIVERY);
    }

    /**
     * @param $products
     *
     * @return null|bool
     */
    public static function getAgeCheckFromProduct($products): ?bool
    {
        $hasAgeCheck = false;

        foreach ($products as $product) {
            $productAgeCheck = self::getAttributeValue(
                'catalog_product_entity_varchar',
                $product['product_id'],
                self::AGE_CHECK
            );

            if (! isset($productAgeCheck) || '' === $productAgeCheck) {
                $hasAgeCheck = null;
            } elseif ('1' === $productAgeCheck) {
                return true;
            }
        }

        return $hasAgeCheck;
    }

    /**
     * @param string $tableName
     * @param string $entityId
     * @param string $column
     *
     * @return null|string
     */
    public static function getAttributeValue(string $tableName, string $entityId, string $column): ?string
    {
        $objectManager = ObjectManager::getInstance();
        $resource      = $objectManager->get(ResourceConnection::class);
        $connection    = $resource->getConnection();
        $attributeId   = self::getAttributeId(
            $connection,
            $resource->getTableName('eav_attribute'),
            $column
        );

        return self::getValueFromAttribute(
            $connection,
            $resource->getTableName($tableName),
            $attributeId,
            $entityId
        );
    }

    /**
     * @param         $connection
     * @param string  $tableName
     * @param string  $databaseColumn
     *
     * @return mixed
     */
    public static function getAttributeId($connection, string $tableName, string $databaseColumn): string
    {
        $sql = $connection
            ->select('entity_type_id')
            ->from($tableName)
            ->where('attribute_code = ?', 'myparcel_' . $databaseColumn)
        ;

        return $connection->fetchOne($sql);
    }

    /**
     * @param object $connection
     * @param string $tableName
     * @param string $attributeId
     * @param string $entityId
     *
     * @return string|null
     */
    public static function getValueFromAttribute(
        $connection,
        string $tableName,
        string $attributeId,
        string $entityId
    ): ?string
    {
        $sql = $connection
            ->select()
            ->from($tableName, ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id = ?', $entityId)
        ;

        return $connection->fetchOne($sql);
    }

    /**
     * @return bool
     */
    public function hasLargeFormat(): bool
    {
        if (CountryCode::isRow($this->cc)) {
            return false;
        }

        return $this->optionIsEnabled(self::LARGE_FORMAT);
    }

    /**
     * @return string
     */
    public function getLabelDescription(): string
    {
        $labelDescription = $this->config->getGeneralConfig(
            'print/label_description',
            (int) $this->order->getStoreId()
        );

        if (! $labelDescription) {
            return '';
        }

        $deliveryOptions  = $this->order->getData(Config::FIELD_DELIVERY_OPTIONS) ?? '{}';
        $checkoutDate     = json_decode($deliveryOptions, true)['date'] ?? null;
        $productInfo      = $this->getItemsCollectionByShipmentId($this->order->getId());
        $labelDescription = str_replace(
            [
                self::ORDER_NUMBER,
                self::DELIVERY_DATE,
                self::PRODUCT_ID,
                self::PRODUCT_NAME,
                self::PRODUCT_QTY,
            ],
            [
                $this->order->getIncrementId(),
                Dating::convertDeliveryDate($checkoutDate, 'd-m-Y') ?: '',
                $this->getProductInfo($productInfo, 'product_id'),
                $this->getProductInfo($productInfo, 'name'),
                $productInfo ? round($this->getProductInfo($productInfo, 'qty')) : null,
            ],
            $labelDescription
        );

        return (string) $labelDescription;
    }

    /**
     * @param array  $productInfo
     * @param string $field
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
     * @param $shipmentId
     *
     * @return array
     */
    public function getItemsCollectionByShipmentId($shipmentId): array
    {
        /** @var ResourceConnection $connection */
        $connection = $this->objectManager->create(ResourceConnection::class);
        $conn       = $connection->getConnection();
        $select     = $conn->select()
                           ->from(
                               ['main_table' => $connection->getTableName('sales_shipment_item')]
                           )
                           ->where('main_table.parent_id=?', $shipmentId)
        ;
        return $conn->fetchAll($select);
    }

    /**
     * Get default value if option === null
     *
     * @param      $optionKey
     *
     * @return bool
     * @internal param $option
     */
    private function optionIsEnabled($optionKey): bool
    {
        return (bool) ($this->options[$optionKey] ??
                       $this->defaultOptions->hasOptionSet($optionKey, $this->carrier));
    }

    /**
     * @return array
     */
    public function getShipmentOptions(): array
    {
        return [
            self::INSURANCE         => $this->getInsurance(),
            self::RETURN            => $this->hasReturn(),
            self::ONLY_RECIPIENT    => $this->hasOnlyRecipient(),
            self::SIGNATURE         => $this->hasSignature(),
            self::COLLECT           => $this->hasCollect(),
            self::RECEIPT_CODE      => $this->hasReceiptCode(),
            self::AGE_CHECK         => $this->hasAgeCheck(),
            self::LARGE_FORMAT      => $this->hasLargeFormat(),
            self::LABEL_DESCRIPTION => $this->getLabelDescription(),
            self::SAME_DAY_DELIVERY => $this->hasSameDayDelivery(),
            self::HIDE_SENDER       => $this->hasHideSender(),
            self::PRIORITY_DELIVERY => $this->hasPriorityDelivery(),
        ];
    }
}
