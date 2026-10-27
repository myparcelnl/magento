<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use BadMethodCallException;
use InvalidArgumentException;
use LogicException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Dating;
use MyParcelNL\Magento\Service\ShipmentOptionsResolver;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesPriceEuro;
use MyParcelNL\Sdk\Model\Shipment\ShipmentOptions as SdkShipmentOptions;
use MyParcelNL\Sdk\Support\Str;
use RuntimeException;

/**
 * What one Magento order is to be shipped as: carrier, package type and the v11 ShipmentOptions.
 *
 * Both export paths ask an order the same questions — ShipmentBuilder for a label,
 * OrderV1Builder for an Order v1 order — so the answers are given once here. Each builder keeps
 * only what its own API shape needs: a recipient array against a Recipient, a RefShipmentPickup
 * against a PickupLocation, shipment item weights against order item weights.
 *
 * Generated-model traps: booleans must be a literal 1 or 0 (a PHP bool throws at serialization),
 * setLabelDescription() throws above its limit where the consignment silently truncated, and
 * setDeliveryType() takes a literal int — a string enum name throws.
 */
class OrderShipmentOptions
{
    /** Copy of the maxLength RefShipmentShipmentOptions::setLabelDescription() enforces; read it from the SDK once the generator exposes it. */
    public const LABEL_DESCRIPTION_MAX_LENGTH = 45;

    private const CARRIERS_WITHOUT_DELIVERY_DATE = ['DPD', 'BPOST'];

    private ObjectManagerInterface $objectManager;
    private JsonSerializer         $jsonSerializer;
    private Dating                 $dating;
    private Order                  $order;
    private DefaultOptions         $defaultOptions;

    private ?DeliveryOptions $deliveryOptions = null;
    private ?ResolvedOptions $chosen          = null;

    /** Null on the Order v1 path: an Order v1 order has no Magento shipment yet. */
    private ?int $shipmentId;

    public function __construct(
        ObjectManagerInterface $objectManager,
        Order                  $order,
        DefaultOptions         $defaultOptions,
        ?int                   $shipmentId = null
    )
    {
        $this->objectManager  = $objectManager;
        $this->jsonSerializer = $objectManager->get(JsonSerializer::class);
        $this->dating         = $objectManager->get(Dating::class);
        $this->order          = $order;
        $this->defaultOptions = $defaultOptions;
        $this->shipmentId     = $shipmentId;
    }

    /**
     * The order's stored delivery options, with the configured default carrier when none is stored.
     *
     * @throws RuntimeException when the order cannot be exported as stored
     */
    public function deliveryOptionsToShip(): DeliveryOptions
    {
        return $this->deliveryOptions ?? ($this->deliveryOptions = $this->parse($this->storedDeliveryOptions()));
    }

    /**
     * The SDK id of the carrier the order ships with.
     *
     * @throws RuntimeException on a carrier the SDK does not know
     */
    public function carrierIdToShip(): int
    {
        $carrier = $this->carrierNameToShip();
        $id      = null === $carrier ? null : Carrier::idFor($carrier);

        if (null === $id) {
            throw new RuntimeException(
                sprintf('carrier "%s" is not one the SDK knows', (string) $carrier)
            );
        }

        return $id;
    }

    /**
     * The package type id the order ships as: the stored type, else the configured default. A stored
     * type that resolves to no id fails the shipment instead of shipping as something else.
     *
     * @throws RuntimeException on a stored type that resolves to no id
     */
    public function packageTypeToShip(): int
    {
        $storedType = $this->deliveryOptionsToShip()->packageTypeValue();

        if ($storedType->isAbsent()) {
            return $this->defaultOptions->getPackageType();
        }

        try {
            return $storedType->toApiValue();
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /** The SDK shipment options for this order: what the resolver chose, plus the export rules below. */
    public function sdkShipmentOptions(): SdkShipmentOptions
    {
        $deliveryOptions = $this->deliveryOptionsToShip();
        $chosen          = $this->chosenOptions();

        $options = (new SdkShipmentOptions())
            ->setPackageType($this->packageTypeToShip())
            ->setDeliveryType($this->deliveryTypeId($deliveryOptions))
            ->setLabelDescription($this->labelDescriptionWithinLimit($chosen))
            ->setSameDayDelivery($this->flag($chosen->hasSameDayDelivery()));

        $this->setKnownToggles($options, $chosen);
        $this->setDiscoveredOptions($options, $chosen);
        $this->dropReturnOnPickup($options, $deliveryOptions);
        $this->setDeliveryDateWhenAccepted($options, $deliveryOptions, $chosen);
        $this->setInsuranceInCents($options, $chosen);

        return $options;
    }

    /** The label description cut to the SDK's limit; setLabelDescription() throws above it. */
    private function labelDescriptionWithinLimit(ResolvedOptions $chosen): string
    {
        return Str::limit((string) $chosen->getLabelDescription(), self::LABEL_DESCRIPTION_MAX_LENGTH);
    }

    /** Every known toggle as 1 or 0. One the SDK cannot set is a bug in the module, so it throws. */
    private function setKnownToggles(SdkShipmentOptions $options, ResolvedOptions $chosen): void
    {
        $values = $chosen->toArray();

        foreach (ShipmentOption::KNOWN_TOGGLES as $option) {
            $setter = SdkShipmentOptions::setters()[$option] ?? null;

            if (null === $setter) {
                throw new LogicException(sprintf('The SDK has no setter for shipment option "%s".', $option));
            }

            $options->{$setter}($this->flag($values[$option] ?? null));
        }
    }

    /** The options only capabilities name. One the SDK cannot set yet is left off, and logged when chosen. */
    private function setDiscoveredOptions(SdkShipmentOptions $options, ResolvedOptions $chosen): void
    {
        foreach ($chosen->discovered() as $option => $isChosen) {
            $setter = SdkShipmentOptions::setters()[$option] ?? null;

            if (null !== $setter) {
                $options->{$setter}($this->flag($isChosen));

                continue;
            }

            if ($isChosen) {
                Logger::notice(sprintf(
                    'Shipment option "%s" left off order %s: the SDK cannot send it yet.',
                    $option,
                    $this->order->getIncrementId()
                ));
            }
        }
    }

    /** A pickup never ships with the return option. */
    private function dropReturnOnPickup(SdkShipmentOptions $options, DeliveryOptions $deliveryOptions): void
    {
        if ($deliveryOptions->isPickup()) {
            $options->setReturn(0);
        }
    }

    /** The order's delivery date, when it has one and acceptsDeliveryDate() allows it. */
    private function setDeliveryDateWhenAccepted(
        SdkShipmentOptions $options,
        DeliveryOptions    $deliveryOptions,
        ResolvedOptions    $chosen
    ): void
    {
        $deliveryDate = $this->dating->convertDeliveryDate($deliveryOptions->getDate(), $this->order);

        if (null !== $deliveryDate && $this->acceptsDeliveryDate($chosen)) {
            $options->setDeliveryDate($deliveryDate);
        }
    }

    /** The insured amount, stored in whole euros, sent in cents. */
    private function setInsuranceInCents(SdkShipmentOptions $options, ResolvedOptions $chosen): void
    {
        $insurance = (int) $chosen->getInsurance();

        if (0 < $insurance) {
            $options->setInsurance(
                (new RefTypesPriceEuro())
                    ->setCurrency(RefTypesPriceEuro::CURRENCY_EUR)
                    ->setAmount($insurance * 100)
            );
        }
    }

    /** The API refuses a delivery date for DPD and bpost, and together with collect. */
    private function acceptsDeliveryDate(ResolvedOptions $chosen): bool
    {
        $v2Carrier = Carrier::toV2Name((string) $this->carrierNameToShip());

        return ! in_array($v2Carrier, self::CARRIERS_WITHOUT_DELIVERY_DATE, true) && ! $chosen->hasCollect();
    }

    private function carrierNameToShip(): ?string
    {
        return $this->deliveryOptionsToShip()->getCarrier();
    }

    /** The options ShipmentOptionsResolver chose for this order, resolved once. */
    private function chosenOptions(): ResolvedOptions
    {
        return $this->chosen ?? ($this->chosen = (new ShipmentOptionsResolver(
            $this->defaultOptions,
            $this->order,
            $this->deliveryOptionsToShip(),
            $this->objectManager,
            $this->carrierNameToShip(),
            $this->shipmentId,
            $this->effectivePackageTypeName()
        ))->resolve());
    }

    /**
     * The type the shipment will carry, as a name, for the capability lookups the resolver makes.
     *
     * Null on a stored type that resolves to nothing: packageTypeToShip() fails the shipment over
     * that, but this is a read for a bound, and failing here would move the throw to whichever
     * caller happened to ask for the options first.
     */
    private function effectivePackageTypeName(): ?string
    {
        try {
            return PackageType::nameFromIdOrNull($this->packageTypeToShip());
        } catch (RuntimeException $e) {
            return null;
        }
    }

    /**
     * The stored delivery options, with the configured default carrier when none is stored. Never
     * another carrier: DefaultOptions answers the default for data it cannot parse, and a pickup
     * under that carrier would ship as a home delivery.
     */
    private function storedDeliveryOptions(): array
    {
        $stored = $this->jsonSerializer->unserialize($this->order->getData(Config::FIELD_DELIVERY_OPTIONS) ?? '[]') ?? [];

        if (empty($stored['carrier'])) {
            $stored['carrier'] = $this->defaultOptions->getCarrierName();
        }

        return $stored;
    }

    /**
     * A pickup that says pickup but carries no readable location is refused rather than degraded.
     * fromOrderFallback() would happily ship it as a home delivery, which is a different delivery
     * from the one the customer paid for, and substituting one is never allowed.
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
            return DeliveryOptions::fromOrderFallback($stored);
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

    /**
     * A literal 1 or 0: RefTypesIntBoolean is checked against [0, 1] with a strict in_array during
     * serialization, so true and false both throw at request time.
     */
    private function flag(?bool $value): int
    {
        return $value ? 1 : 0;
    }
}
