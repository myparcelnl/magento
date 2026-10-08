<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use BadMethodCallException;
use InvalidArgumentException;
use LogicException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Shipment\Capabilities\InsuranceRange;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Dating;
use MyParcelNL\Magento\Service\ShipmentOptionsResolver;
use MyParcelNL\Magento\Service\Text;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesPriceEuro;
use MyParcelNL\Sdk\Model\Shipment\ShipmentOptions as SdkShipmentOptions;
use RuntimeException;

/**
 * What one Magento order is to be shipped as: carrier, package type and the v11 ShipmentOptions.
 *
 * ShipmentBuilder (label) and FulfilmentOrderBuilder (PPS) ask an order the same questions, so the
 * answers live here. Each builder keeps only what its own API shape needs.
 *
 * Generated-model trap: setLabelDescription() throws above its limit.
 */
class OrderShipmentOptions
{
    /**
     * Copy of the maxLength RefShipmentShipmentOptions::setLabelDescription() enforces.
     * Read it from the SDK once the generator exposes it.
     */
    public const LABEL_DESCRIPTION_MAX_LENGTH = 45;

    private const CARRIERS_WITHOUT_DELIVERY_DATE = ['DPD', 'BPOST'];

    private ObjectManagerInterface $objectManager;
    private JsonSerializer         $jsonSerializer;
    private Order                  $order;
    private array                  $options;
    private DefaultOptions         $defaultOptions;

    private ?DeliveryOptions $deliveryOptions = null;
    private ?ResolvedOptions $resolved        = null;

    /** Null on the PPS path: a fulfilment order has no Magento shipment. */
    private ?Shipment $shipment;

    public function __construct(
        ObjectManagerInterface $objectManager,
        Order                  $order,
        array                  $options,
        DefaultOptions         $defaultOptions,
        ?Shipment              $shipment = null
    )
    {
        $this->objectManager  = $objectManager;
        $this->jsonSerializer = $objectManager->get(JsonSerializer::class);
        $this->order          = $order;
        $this->options        = $options;
        $this->defaultOptions = $defaultOptions;
        $this->shipment       = $shipment;
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
            throw new RuntimeException((string) __('carrier "%1" is not one the SDK knows', (string) $carrier));
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
            $this->options,
            $this->shipment,
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
     * Package type precedence: the explicit option, then what the checkout stored, then package.
     * Nothing here overrides a stored type: the API refuses an option the type cannot carry.
     *
     * An unmapped explicit option falls back to the stored type, or package. A stored type is the
     * customer's choice, so one that resolves to no id fails the shipment instead of shipping as another type.
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
     */
    public function shipmentOptions(): SdkShipmentOptions
    {
        $deliveryOptions = $this->deliveryOptions();
        $resolved        = $this->resolved();
        $packageType     = $this->packageType();

        $options = (new SdkShipmentOptions())
            ->setPackageType($packageType)
            ->setDeliveryType($this->deliveryTypeId($deliveryOptions))
            ->setLabelDescription(Text::limit((string) $resolved->getLabelDescription(), self::LABEL_DESCRIPTION_MAX_LENGTH))
            ->setSameDayDelivery($this->flag($resolved->option(ShipmentOption::SAME_DAY_DELIVERY)));

        foreach (ShipmentOption::having('toggle') as $option) {
            $setter = SdkShipmentOptions::setters()[$option] ?? null;

            if (null === $setter) {
                throw new LogicException(sprintf('The SDK has no setter for shipment option "%s".', $option));
            }

            $options->{$setter}($this->flag($resolved->option($option)));
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
                    ->setAmount(InsuranceRange::toCents($insurance))
            );
        }

        return $options;
    }

    /** The API refuses a delivery date for DPD and bpost, and together with collect. */
    private function acceptsDeliveryDate(ResolvedOptions $resolved): bool
    {
        $v2Carrier = Carrier::toV2Name((string) $this->carrierName());

        return ! in_array($v2Carrier, self::CARRIERS_WITHOUT_DELIVERY_DATE, true) && ! $resolved->option(ShipmentOption::COLLECT);
    }

    /**
     * The stored checkout data, with the carrier resolved and a pickup location dropped when the
     * carrier was overridden: a pickup location is carrier-specific, so an inherited one is no
     * longer reachable under a different carrier.
     */
    private function storedDeliveryOptions(): array
    {
        $raw    = (string) $this->order->getData(Config::FIELD_DELIVERY_OPTIONS);
        $stored = '' === $raw ? [] : ($this->jsonSerializer->unserialize($raw) ?? []);

        return DeliveryOptions::withCarrier(
            $stored,
            $this->carrierFromOptions() ?? $this->defaultOptions->getCarrierName()
        );
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
                (string) __('This order is a pickup but its pickup location cannot be read (%1)', $e->getMessage()),
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
