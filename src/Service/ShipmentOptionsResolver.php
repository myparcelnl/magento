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
use MyParcelNL\Magento\Model\Shipment\Capabilities\ShapeLookup;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use Throwable;

/**
 * Decides what shipment options one shipment gets, from the posted options, the configured
 * defaults, the country, the carrier and per-product attributes. Answers with a ShipmentOptions.
 *
 * Takes the parsed DeliveryOptions on purpose: this class used to read the order column itself, and
 * read it two different ways, which is how the receipt code gate broke.
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

    private string $carrier;

    private DefaultOptions $defaultOptions;

    private ObjectManagerInterface $objectManager;

    private array $options;

    private Config $config;

    private Order $order;

    private DeliveryOptions $deliveryOptions;

    private ?string $cc;

    /** Null on the PPS path: a fulfilment order has no Magento shipment yet. */
    private ?int $shipmentId;

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
        array                  $options = [],
        ?int                   $shipmentId = null
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
        $this->shipmentId     = $shipmentId;
    }

    /**
     * The insured amount for this shipment, in whole euros, bounded by what the account's contract
     * allows for this destination and package type.
     *
     * This is the **only** clamp. Both inputs pass through it: an amount posted from the
     * admin New Shipment form and the amount the merchant's configuration resolves to.
     */
    public function getInsurance(): int
    {
        $configured = $this->options['insurance'] ?? $this->defaultOptions->getDefaultInsurance($this->carrier);

        return $this->clampInsurance((int) $configured);
    }

    /**
     * Falls open: bounds we cannot resolve leave the amount alone and let the API decide, rather than
     * shipping a parcel less insured than the merchant asked for.
     */
    private function clampInsurance(int $amount): int
    {
        $range = $this->insuranceRange();

        if (null === $range) {
            return $amount;
        }

        // Zero is not an insured amount of nothing — it means the option is left out of the request
        // entirely, which is why the encoders guard on it before writing anything. So it survives a
        // contract whose minimum is above zero: that minimum bounds what an insured parcel may be
        // insured for, not whether a parcel is insured at all. An order below the configured
        // insurance_from_price still ships uninsured. A contract that compels insurance is the one
        // case where it cannot.
        if (0 === $amount) {
            if (! $range->isRequired()) {
                return 0;
            }

            Logger::notice(sprintf(
                'Insurance for order %s raised to %d, the minimum %s requires.',
                $this->order->getIncrementId(),
                $range->min(),
                $this->carrier
            ));

            return $range->min();
        }

        if ($range->contains($amount)) {
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
        $packageType = $this->deliveryOptions->getPackageType();

        // Both are needed to ask a question narrow enough to trust: without the package type the
        // answer is a union across package types, and a union bound is not this shipment's bound.
        if (null === $this->cc || null === $packageType) {
            return null;
        }

        try {
            $capabilities = $this->objectManager->get(ShapeLookup::class)->forShape(
                (int) $this->order->getStoreId(),
                $this->cc,
                $packageType,
                $this->carrier
            );
        } catch (Throwable $e) {
            Logger::notice('Could not resolve the insurance range', LogContext::of($e));

            return null;
        }

        return InsuranceRange::fromOptionValue(
            $capabilities->optionValue($this->carrier, $packageType, ShipmentOption::INSURANCE)
        );
    }

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
     * Standard delivery only, and only where the account carries it.
     *
     * The country and carrier gates are gone: which carrier offers receipt code where is a
     * capabilities fact, the same reasoning hasAgeCheck() already carried.
     */
    public function hasReceiptCode(): bool
    {
        if (! ShipmentOption::allowedForDeliveryType(
            ShipmentOption::RECEIPT_CODE,
            $this->deliveryOptions->getDeliveryType()
        )) {
            return false;
        }

        return $this->optionIsEnabled(ShipmentOption::RECEIPT_CODE);
    }

    public function hasOnlyRecipient(): bool
    {
        return $this->optionIsEnabled(ShipmentOption::ONLY_RECIPIENT);
    }

    public function hasSameDayDelivery(): bool
    {
        return $this->optionIsEnabled(ShipmentOption::SAME_DAY_DELIVERY);
    }

    public function hasReturn(): bool
    {
        return $this->optionIsEnabled(ShipmentOption::RETURN);
    }

    /** No country gate: which carrier carries an age check where is a capabilities fact. */
    public function hasAgeCheck(): bool
    {
        return $this->optionIsEnabled(ShipmentOption::AGE_CHECK);
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
        return $this->optionIsEnabled(ShipmentOption::PRIORITY_DELIVERY);
    }

    /**
     * What the products say about the age check, or null when they say nothing. Read by
     * DefaultOptions::hasOptionSet(), between the checkout's choice and the carrier setting.
     *
     * Null is the tier's "no opinion", and the caller depends on it: a false seed made an order with
     * no items answer false, which is an opinion, so the carrier-default tier below it never ran.
     *
     * An explicit non-'1' value is still an opinion, and beats the carrier default.
     *
     * @param $products
     */
    public static function getAgeCheckFromProduct($products): ?bool
    {
        $productIds = [];

        foreach ($products as $product) {
            $productIds[] = (int) $product['product_id'];
        }

        // One read for the whole quote. Per product it built its own reader, so nothing it memoised
        // ever survived an iteration and an N-line quote paid 3N queries on the checkout path.
        $ageChecks   = (new ProductAttributes(ObjectManager::getInstance()))
            ->column($productIds, ShipmentOption::AGE_CHECK);
        $hasAgeCheck = null;

        foreach ($productIds as $productId) {
            $productAgeCheck = $ageChecks[$productId] ?? null;

            if ('1' === $productAgeCheck) {
                return true;
            }

            // A product with no value is absent from the map, which is the same "no opinion" the
            // per-product read expressed as null.
            if (null !== $productAgeCheck) {
                $hasAgeCheck = false;
            }
        }

        return $hasAgeCheck;
    }

    public function hasLargeFormat(): bool
    {
        if (CountryCode::isRow($this->cc)) {
            return false;
        }

        return $this->optionIsEnabled(ShipmentOption::LARGE_FORMAT);
    }

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
        $productInfo      = $this->labelProductRows();
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
     * The rows %product_id%, %product_name% and %product_qty% read from.
     *
     * sales_shipment_item.parent_id is the *shipment* entity id, never the order id. A fulfilment
     * order has no shipment yet, so that path reads the order's own items instead.
     */
    private function labelProductRows(): array
    {
        /** @var ResourceConnection $connection */
        $connection = $this->objectManager->create(ResourceConnection::class);
        $conn       = $connection->getConnection();

        $select = null === $this->shipmentId
            ? $conn->select()
                   ->from(['main_table' => $connection->getTableName('sales_order_item')])
                   ->columns(['qty' => 'main_table.qty_ordered'])
                   ->where('main_table.order_id=?', (int) $this->order->getId())
            : $conn->select()
                   ->from(['main_table' => $connection->getTableName('sales_shipment_item')])
                   ->where('main_table.parent_id=?', $this->shipmentId);

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
        return ShipmentOptions::of(
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
