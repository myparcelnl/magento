<?php

namespace MyParcelNL\Magento\Service;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Capabilities\InsuranceRange;
use MyParcelNL\Magento\Model\Shipment\Capabilities\ShapeLookup;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\OptionSource;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use Throwable;

/**
 * Decides what shipment options one shipment gets, from the order's stored options, the configured
 * defaults, the country, the carrier and per-product attributes. Answers with a ShipmentOptions.
 *
 * Takes the parsed DeliveryOptions on purpose: this class used to read the order column itself, and
 * read it two different ways, which is how the receipt code gate broke.
 */
class ShipmentOptionsResolver
{
    // Not a ShipmentOption: a label field, not a toggle the carrier offers.
    private const LABEL_DESCRIPTION = 'label_description';

    /**
     * The checkboxes with a rule of their own; every other one in ShipmentOption::TO_CHECK is taken
     * as chosen. Add an entry only when an option needs one.
     */
    private const RULES = [
        ShipmentOption::SIGNATURE    => 'hasSignature',
        ShipmentOption::RECEIPT_CODE => 'hasReceiptCode',
        ShipmentOption::LARGE_FORMAT => 'hasLargeFormat',
    ];

    private const ORDER_NUMBER      = '%order_nr%';
    private const DELIVERY_DATE     = '%delivery_date%';
    private const PRODUCT_ID        = '%product_id%';
    private const PRODUCT_NAME      = '%product_name%';
    private const PRODUCT_QTY       = '%product_qty%';

    private string $carrier;

    private DefaultOptions $defaultOptions;

    private ObjectManagerInterface $objectManager;

    private Config $config;

    private Order $order;

    private DeliveryOptions $deliveryOptions;

    private ?string $cc;

    /** Null on the PPS path: a fulfilment order has no Magento shipment yet. */
    private ?int $shipmentId;

    /**
     * The type the shipment will actually carry, which is not always the one the checkout stored:
     * the admin form may have changed it. Null narrows nothing, so no capability bound applies.
     */
    private ?string $packageType;

    /** @var array|null the label's product row, read at most once per resolver */
    private ?array $labelProductRows = null;

    /** Whether shapeCapabilities() asked already; its answer may be null. */
    private bool $shapeAsked = false;

    private ?CapabilitySet $shapeCapabilities = null;

    /**
     * @param DefaultOptions         $defaultOptions
     * @param Order                  $order
     * @param DeliveryOptions        $deliveryOptions
     * @param ObjectManagerInterface $objectManager
     * @param string                 $carrier
     */
    public function __construct(
        DefaultOptions         $defaultOptions,
        Order                  $order,
        DeliveryOptions        $deliveryOptions,
        ObjectManagerInterface $objectManager,
        string                 $carrier,
        ?int                   $shipmentId = null,
        ?string                $packageType = null
    )
    {
        $this->defaultOptions  = $defaultOptions;
        $this->deliveryOptions = $deliveryOptions;
        $this->config         = $objectManager->get(Config::class);
        $this->order          = $order;
        $this->objectManager  = $objectManager;
        $this->carrier        = $carrier;
        $this->cc             = $order->getShippingAddress() ? $order->getShippingAddress()->getCountryId() : null;
        $this->shipmentId     = $shipmentId;
        $this->packageType    = $packageType;
    }

    /**
     * The insured amount for this shipment, in whole euros, bounded by what the account's contract
     * allows for this destination and package type.
     *
     * This is the **only** clamp. Both inputs pass through it: the amount the merchant saved on the
     * order and the amount the configuration resolves to.
     */
    public function getInsurance(): int
    {
        return $this->clampInsurance($this->defaultOptions->getDefaultInsurance($this->carrier));
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
        $capabilities = $this->shapeCapabilities();

        if (null === $capabilities) {
            return null;
        }

        return InsuranceRange::fromOptionValue(
            $capabilities->optionValue($this->carrier, (string) $this->packageType, ShipmentOption::INSURANCE)
        );
    }

    /**
     * What the account allows for this exact shipment, or null when that cannot be asked. Shared by
     * the insurance clamp and the dependency passes, so one resolve() asks once.
     */
    private function shapeCapabilities(): ?CapabilitySet
    {
        if ($this->shapeAsked) {
            return $this->shapeCapabilities;
        }

        $this->shapeAsked = true;

        // The exported type, not the stored one. An admin who switches a mailbox order to a package
        // would otherwise be clamped to the mailbox contract and ship under-insured. Both are needed
        // to ask a question narrow enough to trust: without the package type the answer is a union
        // across package types, and a union bound is not this shipment's bound.
        if (null === $this->cc || null === $this->packageType) {
            return null;
        }

        try {
            $this->shapeCapabilities = $this->objectManager->get(ShapeLookup::class)->forShape(
                (int) $this->order->getStoreId(),
                $this->cc,
                $this->packageType,
                $this->carrier
            );
        } catch (Throwable $e) {
            Logger::notice('Could not resolve the capabilities for this shipment', LogContext::of($e));
        }

        return $this->shapeCapabilities;
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

        // One read for the whole quote, through the request's shared reader. Building one here threw
        // its memo away on every call, so an N-line quote paid 3N queries on the checkout path and
        // the New Shipment modal paid one load per carrier and package type it offered.
        $ageChecks   = ObjectManager::getInstance()
            ->get(ProductAttributes::class)
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
        $productInfo      = self::namesAProduct($labelDescription) ? $this->labelProductRows() : [];
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

    /** Whether the template asks for a product at all, so a label that names none costs no query. */
    private static function namesAProduct(string $labelDescription): bool
    {
        foreach ([self::PRODUCT_ID, self::PRODUCT_NAME, self::PRODUCT_QTY] as $placeholder) {
            if (false !== strpos($labelDescription, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The row %product_id%, %product_name% and %product_qty% read from.
     *
     * sales_shipment_item.parent_id is the *shipment* entity id, never the order id. A fulfilment
     * order has no shipment yet, so that path reads the order's own items instead.
     *
     * Ordered and limited: only the first row is ever read, and without an order which row that is
     * would be the database's choice.
     */
    private function labelProductRows(): array
    {
        if (null !== $this->labelProductRows) {
            return $this->labelProductRows;
        }

        /** @var ResourceConnection $connection */
        $connection = $this->objectManager->get(ResourceConnection::class);
        $conn       = $connection->getConnection();

        $select = null === $this->shipmentId
            ? $conn->select()
                   ->from(['main_table' => $connection->getTableName('sales_order_item')], ['product_id', 'name'])
                   ->columns(['qty' => 'main_table.qty_ordered'])
                   ->where('main_table.order_id=?', (int) $this->order->getId())
            : $conn->select()
                   ->from(
                       ['main_table' => $connection->getTableName('sales_shipment_item')],
                       ['product_id', 'name', 'qty']
                   )
                   ->where('main_table.parent_id=?', $this->shipmentId);

        return $this->labelProductRows = $conn->fetchAll($select->order('main_table.entity_id ASC')->limit(1));
    }

    private function optionIsEnabled(string $option): bool
    {
        return $this->defaultOptions->hasOptionSet($option, $this->carrier);
    }

    /**
     * Which OptionSource tier decided an option that is on. The sibling of optionIsEnabled(), read
     * only to settle an excludes conflict.
     */
    private function decidedBy(string $option): int
    {
        if (ShipmentOption::INSURANCE === $option) {
            return OptionSource::CONFIGURATION;
        }

        return $this->defaultOptions->sourceOf($option, $this->carrier) ?? OptionSource::CONFIGURATION;
    }

    /**
     * Every option here is non-null, except extra_assurance, which nothing decides.
     *
     * With the account's capabilities for this shipment, an option they offer and the module has no
     * rule for is decided like any other, an option they do not offer is left off, then excludes and
     * requires are applied. Without them, the options stay as chosen and the API decides.
     */
    public function resolve(): ShipmentOptions
    {
        $values = [
            ShipmentOption::INSURANCE         => $this->getInsurance(),
            self::LABEL_DESCRIPTION           => $this->getLabelDescription(),
            ShipmentOption::SAME_DAY_DELIVERY => $this->hasSameDayDelivery(),
        ];

        foreach (ShipmentOption::TO_CHECK as $option) {
            $values[$option] = isset(self::RULES[$option])
                ? $this->{self::RULES[$option]}()
                : $this->optionIsEnabled($option);
        }

        $capabilities = $this->shapeCapabilities();

        if (null === $capabilities || $capabilities->isPermissive()) {
            return ShipmentOptions::resolved($values);
        }

        $offered = $capabilities->optionsFor($this->carrier, $this->packageType);

        foreach ($offered as $option) {
            if (! array_key_exists($option, $values)) {
                $values[$option] = $this->optionIsEnabled($option);
            }
        }

        $values = $this->dropNotOffered($offered, $values);

        // Excludes first: an option that loses must not leave a companion behind.
        $values = $this->dropExcluded($capabilities, $values);

        return ShipmentOptions::resolved($this->addRequired($capabilities, $values));
    }

    /**
     * Switches off each option the carrier does not offer for this shipment, whatever switched it
     * on, as the PDK does: the API refuses the whole shipment otherwise.
     *
     * @param string[] $offered
     */
    private function dropNotOffered(array $offered, array $values): array
    {
        $options = array_merge(ShipmentOption::TO_CHECK, [ShipmentOption::INSURANCE, ShipmentOption::SAME_DAY_DELIVERY]);

        foreach (array_keys($this->optionsOn($values)) as $option) {
            if (! in_array($option, $options, true) || in_array($option, $offered, true)) {
                continue;
            }

            $values[$option] = ShipmentOption::INSURANCE === $option ? 0 : false;

            Logger::notice(sprintf(
                'Shipment option %s left off order %s: %s does not offer it for this shipment.',
                $option,
                $this->order->getIncrementId(),
                $this->carrier
            ));
        }

        return $values;
    }

    /**
     * Switches off each option that conflicts with one decided on a higher tier. Options are visited
     * best tier first, so an option only ever loses to one that stays.
     */
    private function dropExcluded(CapabilitySet $capabilities, array $values): array
    {
        $ranked = [];

        foreach (array_keys($this->optionsOn($values)) as $position => $option) {
            $ranked[] = [$this->decidedBy($option), $position, $option];
        }

        // The position breaks ties, because usort() is not stable before PHP 8.
        usort($ranked, static function (array $a, array $b): int {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });

        $kept = [];

        foreach ($ranked as [$tier, , $option]) {
            foreach ($kept as $winner => $winnerTier) {
                if ($winnerTier < $tier && $this->excludes($capabilities, $option, $winner)) {
                    $values[$option] = ShipmentOption::INSURANCE === $option ? 0 : false;

                    Logger::notice(sprintf(
                        'Shipment option %s left off order %s: it excludes %s, which was decided with more weight.',
                        $option,
                        $this->order->getIncrementId(),
                        $winner
                    ));

                    continue 2;
                }
            }

            $kept[$option] = $tier;
        }

        return $values;
    }

    /**
     * Switches on each companion an option requires. One level only, and never a companion that an
     * option already on excludes: PostNL insurance requires signature, which receipt code excludes.
     */
    private function addRequired(CapabilitySet $capabilities, array $values): array
    {
        $on = array_keys($this->optionsOn($values));

        foreach ($on as $option) {
            foreach ($capabilities->requiresFor($this->carrier, $this->packageType, $option) as $companion) {
                if ($this->isOn($values, $companion) || $this->excludedByAny($capabilities, $companion, $on)) {
                    continue;
                }

                if (ShipmentOption::INSURANCE === $companion) {
                    $amount = $this->requiredInsurance();

                    if (0 === $amount) {
                        Logger::notice(sprintf(
                            'Order %s: %s requires insurance, but no insurance amount is configured and the contract sets no minimum.',
                            $this->order->getIncrementId(),
                            $option
                        ));

                        continue;
                    }

                    $values[$companion] = $amount;
                } else {
                    $values[$companion] = true;
                }

                // The customer may not have been offered this option, and did not pay for it.
                Logger::notice(sprintf(
                    'Shipment option %s added to order %s, because %s requires it.',
                    $companion,
                    $this->order->getIncrementId(),
                    $option
                ));
            }
        }

        return $values;
    }

    /** The configured amount without the from-price, then the contract minimum, clamped. */
    private function requiredInsurance(): int
    {
        $amount = $this->defaultOptions->getRequiredInsurance($this->carrier);

        if (0 === $amount) {
            $range  = $this->insuranceRange();
            $amount = null === $range ? 0 : $range->min();
        }

        return $this->clampInsurance($amount);
    }

    /** @return array<string,mixed> the entries of $values that are shipment options and on */
    private function optionsOn(array $values): array
    {
        unset($values[self::LABEL_DESCRIPTION]);

        return array_filter($values, function (string $option) use ($values): bool {
            return $this->isOn($values, $option);
        }, ARRAY_FILTER_USE_KEY);
    }

    private function isOn(array $values, string $option): bool
    {
        if (ShipmentOption::INSURANCE === $option) {
            return 0 < (int) ($values[$option] ?? 0);
        }

        return (bool) ($values[$option] ?? false);
    }

    /** Either side may list the exclusion: the API does not promise to list it on both. */
    private function excludes(CapabilitySet $capabilities, string $option, string $other): bool
    {
        return in_array($other, $capabilities->excludesFor($this->carrier, $this->packageType, $option), true)
               || in_array($option, $capabilities->excludesFor($this->carrier, $this->packageType, $other), true);
    }

    /** @param string[] $options */
    private function excludedByAny(CapabilitySet $capabilities, string $option, array $options): bool
    {
        foreach ($options as $other) {
            if ($this->excludes($capabilities, $option, $other)) {
                return true;
            }
        }

        return false;
    }
}
