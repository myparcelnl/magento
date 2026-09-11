<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use BadMethodCallException;
use InvalidArgumentException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Dating;
use MyParcelNL\Magento\Service\ShipmentOptionsResolver;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesPriceEuro;
use MyParcelNL\Sdk\Model\Shipment\Carrier as SdkCarrier;
use MyParcelNL\Sdk\Model\Shipment\ShipmentOptions as SdkShipmentOptions;
use MyParcelNL\Sdk\Support\Str;
use RuntimeException;

/**
 * What one Magento order is to be shipped as: carrier, package type and the v11 ShipmentOptions.
 *
 * Both export paths ask an order the same questions — ShipmentBuilder for a label,
 * FulfilmentOrderBuilder for a PPS order — so the answers are given once here. Each builder keeps
 * only what its own API shape needs: a recipient array against a Recipient, a RefShipmentPickup
 * against a PickupLocation, shipment item weights against order item weights.
 *
 * Generated-model traps: booleans must be a literal 1 or 0 (a PHP bool throws at serialization),
 * setLabelDescription() throws above its limit where the consignment silently truncated, and
 * setDeliveryType() takes a literal int — a string enum name throws.
 */
class OrderShipmentOptions
{
    public const LABEL_DESCRIPTION_MAX_LENGTH = 45;

    private ObjectManagerInterface $objectManager;
    private JsonSerializer         $jsonSerializer;
    private Order                  $order;
    private array                  $options;
    private DefaultOptions         $defaultOptions;

    private ?DeliveryOptions $deliveryOptions = null;
    private ?ResolvedOptions $resolved        = null;

    public function __construct(
        ObjectManagerInterface $objectManager,
        Order                  $order,
        array                  $options,
        DefaultOptions         $defaultOptions
    )
    {
        $this->objectManager  = $objectManager;
        $this->jsonSerializer = $objectManager->get(JsonSerializer::class);
        $this->order          = $order;
        $this->options        = $options;
        $this->defaultOptions = $defaultOptions;
    }

    /** @throws RuntimeException when the order cannot be exported as stored */
    public function deliveryOptions(): DeliveryOptions
    {
        return $this->deliveryOptions ?? ($this->deliveryOptions = $this->parse($this->storedDeliveryOptions()));
    }

    public function carrierName(): ?string
    {
        return $this->deliveryOptions()->getCarrier();
    }

    /** @throws RuntimeException on a carrier no module name covers */
    public function carrierId(): int
    {
        $carrier = $this->carrierName();
        $v2Name  = null === $carrier ? null : Carrier::toV2Name($carrier);

        if (null === $v2Name) {
            throw new RuntimeException(
                sprintf('carrier "%s" is not one this module knows', (string) $carrier)
            );
        }

        return SdkCarrier::toId($v2Name);
    }

    public function resolved(): ResolvedOptions
    {
        return $this->resolved ?? ($this->resolved = (new ShipmentOptionsResolver(
            $this->defaultOptions,
            $this->order,
            $this->deliveryOptions(),
            $this->objectManager,
            $this->carrierName(),
            $this->options
        ))->resolve());
    }

    /**
     * Package type precedence: the explicit option, then what the checkout stored, then the
     * configured default. Nothing here overrides a stored type — an option the type cannot carry
     * is the checkout's problem to avoid and the API's to refuse.
     *
     * The two sources fail differently, and deliberately. An explicit option is the admin form's own
     * vocabulary, so an unmapped one falls back the way it always has. A *stored* type is the
     * customer's choice, so an unresolvable one fails the shipment instead of shipping as something
     * else (FR-000010).
     *
     * @throws RuntimeException on a stored type that resolves to no id
     */
    public function packageType(): int
    {
        $explicit = $this->options['package_type'] ?? DefaultOptions::DEFAULT_OPTION_VALUE;

        if (DefaultOptions::DEFAULT_OPTION_VALUE !== $explicit) {
            return is_numeric($explicit)
                ? (int) $explicit
                : (PackageType::toIdOrNull((string) $explicit) ?? $this->defaultOptions->getPackageType());
        }

        $storedType = $this->deliveryOptions()->packageTypeValue();

        if ($storedType->isAbsent()) {
            return $this->defaultOptions->getPackageType();
        }

        try {
            return $storedType->toApiValue();
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Every boolean is a literal 1 or 0: RefTypesIntBoolean is checked against [0, 1] with a strict
     * in_array during serialization, so true and false both throw at request time.
     *
     * @param \Magento\Sales\Model\Order\Address|\Magento\Sales\Model\Order\Shipment\Address $address
     */
    public function shipmentOptions($address): SdkShipmentOptions
    {
        $deliveryOptions = $this->deliveryOptions();
        $resolved        = $this->resolved();
        $packageType     = $this->packageType();

        $options = (new SdkShipmentOptions())
            ->setPackageType($packageType)
            ->setDeliveryType($this->deliveryTypeId($deliveryOptions))
            ->setLabelDescription(Str::limit((string) $resolved->getLabelDescription(), self::LABEL_DESCRIPTION_MAX_LENGTH))
            // Receipt code first: it blocks the options below it.
            ->setReceiptCode($this->flag($resolved->hasReceiptCode()))
            ->setOnlyRecipient($this->flag($resolved->hasOnlyRecipient()))
            ->setSignature($this->flag($resolved->hasSignature()))
            ->setCollect($this->flag($resolved->hasCollect()))
            ->setReturn($this->flag($deliveryOptions->isPickup() ? false : $resolved->hasReturn()))
            ->setSameDayDelivery($this->flag($resolved->hasSameDayDelivery()))
            ->setLargeFormat($this->flag($resolved->hasLargeFormat()))
            ->setAgeCheck($this->flag($resolved->hasAgeCheck()))
            ->setPriorityDelivery($this->flag($resolved->hasPriorityDelivery()));

        $deliveryDate = $this->deliveryDate($deliveryOptions, $packageType, $address);

        if (null !== $deliveryDate) {
            $options->setDeliveryDate($deliveryDate);
        }

        $insurance = (int) $resolved->getInsurance();

        if (0 < $insurance) {
            $options->setInsurance(
                (new RefTypesPriceEuro())
                    ->setCurrency(RefTypesPriceEuro::CURRENCY_EUR)
                    ->setAmount($insurance * 100)
            );
        }

        return $options;
    }

    /**
     * The stored checkout data, with the carrier resolved and a pickup location dropped when the
     * carrier was overridden — a pickup location is carrier-specific, so an inherited one is no
     * longer reachable under a different carrier.
     */
    private function storedDeliveryOptions(): array
    {
        $stored          = $this->jsonSerializer->unserialize($this->order->getData(Config::FIELD_DELIVERY_OPTIONS) ?? '[]') ?? [];
        $checkoutCarrier = $stored['carrier'] ?? null;
        $selected        = $this->carrierFromOptions() ?? $this->defaultOptions->getCarrierName();

        $stored['carrier'] = $selected;

        if ($checkoutCarrier && $selected !== $checkoutCarrier && ! empty($stored['isPickup'])) {
            unset($stored['pickupLocation']);
            $stored['isPickup']     = false;
            $stored['deliveryType'] = DeliveryType::STANDARD_NAME;
        }

        return $stored;
    }

    /**
     * A pickup that says pickup but carries no readable location is refused rather than degraded.
     * fromOrderFallback() would happily ship it as a home delivery, which is a different delivery
     * from the one the customer paid for, a substitution FR-000010 forbids.
     *
     * @throws RuntimeException
     */
    private function parse(array $stored): DeliveryOptions
    {
        try {
            return DeliveryOptionsFactory::create($stored);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(
                sprintf('This order is a pickup but its pickup location cannot be read (%s)', $e->getMessage()),
                0,
                $e
            );
        } catch (BadMethodCallException $e) {
            return DeliveryOptions::fromOrderFallback($stored + $this->options);
        }
    }

    /** @throws RuntimeException on a stored delivery type that resolves to nothing sendable */
    private function deliveryTypeId(DeliveryOptions $deliveryOptions): int
    {
        $value = $deliveryOptions->deliveryTypeValue();

        if ($value->isAbsent()) {
            return DeliveryType::STANDARD;
        }

        try {
            return $value->toApiValue();
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function deliveryDate(DeliveryOptions $deliveryOptions, int $packageType, $address): ?string
    {
        if (PackageType::PACKAGE_SMALL === $packageType && CountryCode::CC_NL !== $address->getCountryId()) {
            return null;
        }

        return Dating::convertDeliveryDate($deliveryOptions->getDate());
    }

    private function carrierFromOptions(): ?string
    {
        if (empty($this->options['carrier'])) {
            return null;
        }

        return DefaultOptions::DEFAULT_OPTION_VALUE === $this->options['carrier']
            ? $this->defaultOptions->getCarrierName()
            : $this->options['carrier'];
    }

    private function flag(?bool $value): int
    {
        return $value ? 1 : 0;
    }
}
