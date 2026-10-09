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

    private const CARRIERS_WITHOUT_DELIVERY_DATE = ['DPD', 'BPOST'];

    private ObjectManagerInterface $objectManager;
    private JsonSerializer         $jsonSerializer;
    private Order                  $order;
    private DefaultOptions         $defaultOptions;

    private ?DeliveryOptions $deliveryOptions = null;
    private ?ResolvedOptions $resolved        = null;

    /** Null on the PPS path: a fulfilment order has no Magento shipment yet. */
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
        $this->order          = $order;
        $this->defaultOptions = $defaultOptions;
        $this->shipmentId     = $shipmentId;
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

    /** @throws RuntimeException on a carrier the SDK does not know */
    public function carrierId(): int
    {
        $carrier = $this->carrierName();
        $id      = null === $carrier ? null : Carrier::idFor($carrier);

        if (null === $id) {
            throw new RuntimeException(
                sprintf('carrier "%s" is not one the SDK knows', (string) $carrier)
            );
        }

        return $id;
    }

    public function resolved(): ResolvedOptions
    {
        return $this->resolved ?? ($this->resolved = (new ShipmentOptionsResolver(
            $this->defaultOptions,
            $this->order,
            $this->deliveryOptions(),
            $this->objectManager,
            $this->carrierName(),
            $this->shipmentId,
            $this->effectivePackageTypeName()
        ))->resolve());
    }

    /**
     * The type the shipment will carry, as a name, for the capability lookups the resolver makes.
     *
     * Null on a stored type that resolves to nothing: packageType() fails the shipment over that,
     * but this is a read for a bound, and failing here would move the throw to whichever caller
     * happened to ask for the options first.
     */
    private function effectivePackageTypeName(): ?string
    {
        try {
            return PackageType::nameFromIdOrNull($this->packageType());
        } catch (RuntimeException $e) {
            return null;
        }
    }

    /**
     * The stored type, else the configured default. A stored type that resolves to no id fails the
     * shipment instead of shipping as something else.
     *
     * @throws RuntimeException on a stored type that resolves to no id
     */
    public function packageType(): int
    {
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
     */
    public function shipmentOptions(): SdkShipmentOptions
    {
        $deliveryOptions = $this->deliveryOptions();
        $resolved        = $this->resolved();
        $packageType     = $this->packageType();

        $options = (new SdkShipmentOptions())
            ->setPackageType($packageType)
            ->setDeliveryType($this->deliveryTypeId($deliveryOptions))
            ->setLabelDescription(Str::limit((string) $resolved->getLabelDescription(), self::LABEL_DESCRIPTION_MAX_LENGTH))
            ->setSameDayDelivery($this->flag($resolved->hasSameDayDelivery()));

        $values = $resolved->toArray();

        foreach (ShipmentOption::TO_CHECK as $option) {
            $setter = SdkShipmentOptions::setters()[$option] ?? null;

            if (null === $setter) {
                throw new LogicException(sprintf('The SDK has no setter for shipment option "%s".', $option));
            }

            $options->{$setter}($this->flag($values[$option] ?? null));
        }

        foreach ($resolved->discovered() as $option => $chosen) {
            $setter = SdkShipmentOptions::setters()[$option] ?? null;

            if (null !== $setter) {
                $options->{$setter}($this->flag($chosen));

                continue;
            }

            if ($chosen) {
                Logger::notice(sprintf(
                    'Shipment option "%s" left off order %s: the SDK cannot send it yet.',
                    $option,
                    $this->order->getIncrementId()
                ));
            }
        }

        if ($deliveryOptions->isPickup()) {
            $options->setReturn(0);
        }

        $deliveryDate = Dating::convertDeliveryDate($deliveryOptions->getDate());

        if (null !== $deliveryDate && $this->acceptsDeliveryDate($resolved)) {
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

    /** The API refuses a delivery date for DPD and bpost, and together with collect. */
    private function acceptsDeliveryDate(ResolvedOptions $resolved): bool
    {
        $v2Carrier = Carrier::toV2Name((string) $this->carrierName());

        return ! in_array($v2Carrier, self::CARRIERS_WITHOUT_DELIVERY_DATE, true) && ! $resolved->hasCollect();
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

    private function flag(?bool $value): int
    {
        return $value ? 1 : 0;
    }
}
