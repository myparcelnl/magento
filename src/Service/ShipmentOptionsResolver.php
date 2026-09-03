<?php

namespace MyParcelNL\Magento\Service;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Shipment\Capabilities\InsuranceRange;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Model\Shipment\Carrier as ShipmentCarrier;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use Throwable;

/**
 * Decides what shipment options one shipment gets, from the posted options, the configured
 * defaults, the country, the carrier and per-product attributes. Answers with a ShipmentOptions.
 *
 * Takes the parsed DeliveryOptions on purpose: this class used to read the order column itself, and
 * read it two different ways, which is how the receipt code gate broke (DR-14).
 */
class ShipmentOptionsResolver
{
    // Not a ShipmentOption: a label field, not a toggle the carrier offers.
    private const LABEL_DESCRIPTION = 'label_description';

    private const ORDER_NUMBER      = '%order_nr%';
    private const DELIVERY_DATE     = '%delivery_date%';
    private const PRODUCT_ID        = '%product_id%';
    private const PRODUCT_NAME      = '%product_name%';
    private const PRODUCT_QTY       = '%product_qty%';

    /** @var string */
    private $carrier;

    /** @var DefaultOptions */
    private $defaultOptions;

    /** @var ObjectManagerInterface */
    private $objectManager;

    /** @var array */
    private $options;

    /** @var Config */
    private $config;

    /** @var Order */
    private $order;

    /** @var DeliveryOptions */
    private $deliveryOptions;

    /** @var string|null */
    private ?string $cc;

    /**
     * @param DefaultOptions         $defaultOptions
     * @param Order                  $order
     * @param DeliveryOptions        $deliveryOptions
     * @param ObjectManagerInterface $objectManager
     * @param string                 $carrier
     * @param array                  $options
     */
    public function __construct(
        DefaultOptions         $defaultOptions,
        Order                  $order,
        DeliveryOptions        $deliveryOptions,
        ObjectManagerInterface $objectManager,
        string                 $carrier,
        array                  $options = []
    )
    {
        $this->defaultOptions  = $defaultOptions;
        $this->deliveryOptions = $deliveryOptions;
        $this->config         = $objectManager->get(Config::class);
        $this->order          = $order;
        $this->objectManager  = $objectManager;
        $this->carrier        = $carrier;
        $this->options        = $options;
        $this->cc             = $order->getShippingAddress() ? $order->getShippingAddress()->getCountryId() : null;
    }

    /**
     * The insured amount for this shipment, in whole euros, bounded by what the account's contract
     * allows for this destination and package type.
     *
     * This is the **only** clamp (DR-19). Both inputs pass through it: an amount posted from the
     * admin New Shipment form and the amount the merchant's configuration resolves to.
     */
    public function getInsurance(): int
    {
        $configured = $this->options['insurance'] ?? $this->defaultOptions->getDefaultInsurance($this->carrier);

        return $this->clampInsurance((int) $configured);
    }

    /**
     * Falls open: bounds we cannot resolve leave the amount alone and let the API decide, rather than
     * shipping a parcel less insured than the merchant asked for (FR-000009 criterion 5).
     */
    private function clampInsurance(int $amount): int
    {
        // Zero is not an insured amount of nothing — it means the option is left out of the request
        // entirely, which is why the encoders guard on it before writing anything. So it survives a
        // contract whose minimum is above zero: that minimum bounds what an insured parcel may be
        // insured for, not whether a parcel is insured at all. An order below the configured
        // insurance_from_price still ships uninsured.
        if (0 === $amount) {
            return 0;
        }

        $range = $this->insuranceRange();

        if (null === $range || $range->contains($amount)) {
            return $amount;
        }

        $clamped = $range->clamp($amount);

        Logger::notice(sprintf(
            'Insurance for order %s clamped from %d to %d, the contract range for %s being %d-%d.',
            $this->order->getIncrementId(),
            $amount,
            $clamped,
            $this->carrier,
            $range->min(),
            $range->max()
        ));

        return $clamped;
    }

    private function insuranceRange(): ?InsuranceRange
    {
        $v2Carrier     = ShipmentCarrier::toV2Name($this->carrier);
        $packageType   = $this->deliveryOptions->getPackageType();
        $v2PackageType = null === $packageType ? null : PackageType::toV2Name($packageType);

        // Every one of these is needed to ask a question narrow enough to trust: without the package
        // type the answer is a union across package types (DR-18), and a union bound is not this
        // shipment's bound.
        if (null === $this->cc || null === $v2Carrier || null === $v2PackageType) {
            return null;
        }

        try {
            $capabilities = $this->objectManager->get(CapabilitiesRepository::class)->forStore(
                (int) $this->order->getStoreId(),
                CapabilitiesRequest::forCountry($this->cc)
                    ->withCarrier($v2Carrier)
                    ->withPackageType($v2PackageType)
            );
        } catch (Throwable $e) {
            Logger::notice('Could not resolve the insurance range', LogContext::of($e));

            return null;
        }

        return InsuranceRange::fromOptionValue(
            $capabilities->optionValue($this->carrier, $packageType, ShipmentOption::INSURANCE)
        );
    }

    /** @return bool */
    public function hasSignature(): bool
    {
        if (CountryCode::CC_BE === $this->cc && $this->hasOnlyRecipient()) {
            return false;
        }

        return $this->optionIsEnabled(ShipmentOption::SIGNATURE);
    }

    public function hasCollect(): bool
    {
        return $this->optionIsEnabled(ShipmentOption::COLLECT);
    }

    /**
     * PostNL, NL and standard delivery only. An order with no stored delivery type counts as
     * standard, so the admin New Shipment form keeps working.
     */
    public function hasReceiptCode(): bool
    {
        $deliveryType = $this->deliveryOptions->getDeliveryType() ?? DeliveryType::DEFAULT_NAME;

        if (CountryCode::CC_NL !== $this->cc
            || CarrierPostNL::NAME !== $this->carrier
            || DeliveryType::STANDARD_NAME !== $deliveryType
        ) {
            return false;
        }

        return $this->optionIsEnabled(ShipmentOption::RECEIPT_CODE);
    }

    /** @return bool */
    public function hasOnlyRecipient(): bool
    {
        return $this->optionIsEnabled(ShipmentOption::ONLY_RECIPIENT);
    }

    /** @return bool */
    public function hasSameDayDelivery(): bool
    {
        return $this->optionIsEnabled(ShipmentOption::SAME_DAY_DELIVERY);
    }

    /** @return bool */
    public function hasReturn(): bool
    {
        return $this->optionIsEnabled(ShipmentOption::RETURN);
    }

    /** @return bool */
    public function hasAgeCheck(): bool
    {
        if (CountryCode::CC_NL !== $this->cc) {
            return false;
        }

        $ageCheckFromOptions  = $this->options[ShipmentOption::AGE_CHECK] ?? null;
        $ageCheckOfProduct    = self::getAgeCheckFromProduct($this->order->getItems());
        $ageCheckFromSettings = $this->defaultOptions->hasDefaultOption($this->carrier, ShipmentOption::AGE_CHECK);

        return $ageCheckFromOptions ?? $ageCheckOfProduct ?? $ageCheckFromSettings;
    }

    public function hasHideSender(): bool
    {
        return $this->optionIsEnabled(ShipmentOption::HIDE_SENDER);
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

        return $this->optionIsEnabled(ShipmentOption::PRIORITY_DELIVERY);
    }

    /**
     * What the products say about the age check, or null when they say nothing.
     *
     * Null is the tier's "no opinion", and the caller's `??` depends on it: starting at false meant
     * an order with no items answered false, which is an opinion, so the carrier-default tier below
     * it could never run. That was the surviving half of DR-7 — passing the items rather than the
     * Track fixed the loop, not the seed.
     *
     * An explicit non-'1' value is still an opinion, and beats the carrier default.
     *
     * @param $products
     */
    public static function getAgeCheckFromProduct($products): ?bool
    {
        $hasAgeCheck = null;

        foreach ($products as $product) {
            $productAgeCheck = self::getAttributeValue(
                'catalog_product_entity_varchar',
                $product['product_id'],
                ShipmentOption::AGE_CHECK
            );

            if ('1' === $productAgeCheck) {
                return true;
            }

            if (isset($productAgeCheck) && '' !== $productAgeCheck) {
                $hasAgeCheck = false;
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

    /** @return bool */
    public function hasLargeFormat(): bool
    {
        if (CountryCode::isRow($this->cc)) {
            return false;
        }

        return $this->optionIsEnabled(ShipmentOption::LARGE_FORMAT);
    }

    /** @return string */
    public function getLabelDescription(): string
    {
        $labelDescription = $this->config->getGeneralConfig(
            'print/label_description',
            (int) $this->order->getStoreId()
        );

        if (! $labelDescription) {
            return '';
        }

        $checkoutDate     = $this->deliveryOptions->getDate();
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

    /** Every option here is non-null, except extra_assurance, which nothing decides. */
    public function resolve(): ShipmentOptions
    {
        return ShipmentOptions::resolved(
            [
                ShipmentOption::INSURANCE         => $this->getInsurance(),
                ShipmentOption::RETURN            => $this->hasReturn(),
                ShipmentOption::ONLY_RECIPIENT    => $this->hasOnlyRecipient(),
                ShipmentOption::SIGNATURE         => $this->hasSignature(),
                ShipmentOption::COLLECT           => $this->hasCollect(),
                ShipmentOption::RECEIPT_CODE      => $this->hasReceiptCode(),
                ShipmentOption::AGE_CHECK         => $this->hasAgeCheck(),
                ShipmentOption::LARGE_FORMAT      => $this->hasLargeFormat(),
                self::LABEL_DESCRIPTION           => $this->getLabelDescription(),
                ShipmentOption::SAME_DAY_DELIVERY => $this->hasSameDayDelivery(),
                ShipmentOption::HIDE_SENDER       => $this->hasHideSender(),
                ShipmentOption::PRIORITY_DELIVERY => $this->hasPriorityDelivery(),
            ]
        );
    }
}
