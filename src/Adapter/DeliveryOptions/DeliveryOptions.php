<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter\DeliveryOptions;

use InvalidArgumentException;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Shipment\TypeValue;
use MyParcelNL\Sdk\Support\Str;

/**
 * The delivery options stored on an order: carrier, date, delivery type, package type, shipment
 * options, for a pickup the location, and what a merchant saved for the label. Build one through
 * DeliveryOptionsFactory.
 *
 * toArray() is a persisted and published format — quote data, and Magento's order REST API — so its
 * key order is part of the contract.
 *
 * The two types are value objects so an unrecognised one survives instead of being defaulted. The
 * plain getters answer with the name; the *Value() getters say whether it resolved.
 */
final class DeliveryOptions
{
    public const DIMENSIONS = ['length', 'width', 'height'];

    // NeedsQuoteProps and Carrier read these off an empty instance, so '' rather than null matters.
    private const DEFAULT_DATE          = '';
    private const DEFAULT_DELIVERY_TYPE = DeliveryType::STANDARD_NAME;

    private ?string $carrier;

    private ?string $date;

    private TypeValue $deliveryType;

    private TypeValue $packageType;

    private ?PickupLocation $pickupLocation;

    private ?ShipmentOptions $shipmentOptions;

    private ?int $labelAmount;

    private ?int $digitalStampWeight;

    /** @var array<string,int> length, width and height in cm, only the ones set */
    private array $dimensions;

    /** @var string[] the shipment options the merchant switched on, which outrank the checkout's */
    private array $merchantOptions;

    /**
     * @param string|int|null $deliveryType
     * @param string|int|null $packageType
     */
    private function __construct(
        ?string $carrier,
        ?string $date,
        $deliveryType,
        $packageType,
        ?ShipmentOptions $shipmentOptions,
        ?PickupLocation $pickupLocation,
        ?int $labelAmount = null,
        ?int $digitalStampWeight = null,
        array $dimensions = [],
        array $merchantOptions = []
    ) {
        $this->carrier         = $carrier;
        $this->date            = $date;
        $this->deliveryType    = TypeValue::fromStored($deliveryType, DeliveryType::class);
        $this->packageType     = TypeValue::fromStored($packageType, PackageType::class);
        $this->shipmentOptions = $shipmentOptions;
        $this->pickupLocation  = $pickupLocation;
        $this->labelAmount        = $labelAmount;
        $this->digitalStampWeight = $digitalStampWeight;
        $this->dimensions         = $dimensions;
        $this->merchantOptions    = $merchantOptions;
    }

    /** @throws \InvalidArgumentException when the options say pickup but carry no location */
    public static function fromCheckoutData(array $data): self
    {
        $data         = self::normaliseNestedKeys($data);
        $deliveryType = $data['deliveryType'] ?? null;
        $pickup       = null;

        if (DeliveryType::PICKUP_NAME === $deliveryType) {
            if (! isset($data['pickupLocation']) || ! is_array($data['pickupLocation'])) {
                throw new InvalidArgumentException('Delivery options say pickup but carry no pickupLocation');
            }

            $pickup = PickupLocation::fromCheckoutData($data['pickupLocation']);
        }

        return new self(
            $data['carrier'] ?? null,
            $data['date'] ?? null,
            $deliveryType,
            $data['packageType'] ?? null,
            ShipmentOptions::of($data['shipmentOptions'] ?? []),
            $pickup,
            self::intOrNull($data['labelAmount'] ?? null),
            self::intOrNull($data['digitalStampWeight'] ?? null),
            self::dimensionsOf($data['physicalProperties'] ?? null),
            self::optionNamesOf($data['merchantOptions'] ?? null)
        );
    }

    /**
     * The old shape: delivery type as an id inside a 'time' list, options under 'options', pickup
     * fields at the top level.
     */
    public static function fromLegacyCheckoutData(array $data): self
    {
        $data           = self::normaliseNestedKeys($data);
        $deliveryTypeId = $data['time'][0]['type'] ?? null;
        $deliveryType   = null === $deliveryTypeId
            ? null
            : DeliveryType::nameFromIdOrNull((int) $deliveryTypeId);

        return new self(
            $data['carrier'] ?? null,
            $data['date'] ?? null,
            $deliveryType,
            null,
            ShipmentOptions::fromLegacyCheckoutData($data['options'] ?? []),
            DeliveryType::PICKUP_NAME === $deliveryType ? PickupLocation::fromLegacyCheckoutData($data) : null
        );
    }

    /**
     * Stored data in no recognised shape, merged with the options the admin posted.
     *
     * Reads whatever it is given, including a pickup location — unlike fromCheckoutData() it never
     * refuses one that is absent, because this is the degrade path.
     */
    public static function fromOrderFallback(array $data): self
    {
        $data   = self::normaliseNestedKeys($data);
        $pickup = isset($data['pickupLocation']) && is_array($data['pickupLocation'])
            ? PickupLocation::fromCheckoutData($data['pickupLocation'])
            : null;

        return new self(
            $data['carrier'] ?? null,
            $data['date'] ?? null,
            $data['deliveryType'] ?? null,
            $data['packageType'] ?? null,
            ShipmentOptions::fromMagentoOptions($data),
            $pickup,
            self::intOrNull($data['labelAmount'] ?? null),
            self::intOrNull($data['digitalStampWeight'] ?? null),
            self::dimensionsOf($data['physicalProperties'] ?? null),
            self::optionNamesOf($data['merchantOptions'] ?? null)
        );
    }

    /**
     * Stored checkout data with every false shipment option as null. The widget writes false for an
     * option it offered but the customer did not tick, which must inherit the carrier setting, not
     * switch it off. Works on the raw array, so every other key survives as it was.
     */
    public static function inheritUnticked(array $data): array
    {
        if (! isset($data['shipmentOptions']) || ! is_array($data['shipmentOptions'])) {
            return $data;
        }

        foreach ($data['shipmentOptions'] as $key => $value) {
            if (false === $value) {
                $data['shipmentOptions'][$key] = null;
            }
        }

        return $data;
    }

    /**
     * Stored data under another carrier. A pickup location belongs to its carrier, so a pickup
     * under a different carrier becomes a standard home delivery.
     */
    public static function withCarrier(array $data, string $carrier): array
    {
        $previous        = $data['carrier'] ?? null;
        $data['carrier'] = $carrier;

        if ($previous && $carrier !== $previous && ! empty($data['isPickup'])) {
            $data['pickupLocation'] = null;
            $data['isPickup']       = false;
            $data['deliveryType']   = DeliveryType::STANDARD_NAME;
        }

        return $data;
    }

    /** inheritUnticked() on stored JSON. Anything that does not decode to an array stays as it was. */
    public static function inheritUntickedInJson(string $json): string
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return $json;
        }

        return json_encode(self::inheritUnticked($data), JSON_THROW_ON_ERROR);
    }

    /** @return array<string,int> the positive whole length, width and height, in that order */
    private static function dimensionsOf($physicalProperties): array
    {
        $dimensions = [];

        foreach (self::DIMENSIONS as $dimension) {
            $value = is_array($physicalProperties) ? self::intOrNull($physicalProperties[$dimension] ?? null) : null;

            if (null !== $value && 0 < $value) {
                $dimensions[$dimension] = $value;
            }
        }

        return $dimensions;
    }

    /** @return string[] */
    private static function optionNamesOf($names): array
    {
        return is_array($names) ? array_values(array_unique(array_filter($names, [ShipmentOption::class, 'isOptionName']))) : [];
    }

    private static function intOrNull($value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The widget sends these two nested objects in camelCase, and a toArray() round trip writes them
     * back in snake_case, so both spellings exist in the database. The top level stays camelCase.
     *
     * Every named constructor calls this on its own input rather than trusting the caller to have
     * done it. Trusting the caller is what let fromOrderFallback() drop a pickup location silently
     * for six years. Idempotent, so a second pass costs nothing.
     */
    private static function normaliseNestedKeys(array $data): array
    {
        foreach (['shipmentOptions', 'pickupLocation'] as $nested) {
            if (! isset($data[$nested]) || ! is_array($data[$nested])) {
                continue;
            }

            foreach ($data[$nested] as $key => $value) {
                $snakeCased = Str::snake((string) $key);

                if ($snakeCased === $key) {
                    continue;
                }

                unset($data[$nested][$key]);
                $data[$nested][$snakeCased] = $value;
            }
        }

        return $data;
    }

    /** Nothing stored, or unreadable: standard delivery, no date, no options. */
    public static function defaults(): self
    {
        return new self(
            null,
            self::DEFAULT_DATE,
            self::DEFAULT_DELIVERY_TYPE,
            null,
            ShipmentOptions::of([]),
            null
        );
    }

    public function getCarrier(): ?string
    {
        return $this->carrier;
    }

    public function getDate(): ?string
    {
        return $this->date;
    }

    public function getDeliveryType(): ?string
    {
        return $this->deliveryType->name();
    }

    public function getPackageType(): ?string
    {
        return $this->packageType->name();
    }

    public function getPickupLocation(): ?PickupLocation
    {
        return $this->pickupLocation;
    }

    public function getShipmentOptions(): ?ShipmentOptions
    {
        return $this->shipmentOptions;
    }

    /** The number of collo a merchant saved; null when none was saved. */
    public function getLabelAmount(): ?int
    {
        return $this->labelAmount;
    }

    /** The digital stamp weight in grams a merchant saved; null when none was saved. */
    public function getDigitalStampWeight(): ?int
    {
        return $this->digitalStampWeight;
    }

    /** @return array<string,int> the length, width and height in cm a merchant saved, only the ones set */
    public function getDimensions(): array
    {
        return $this->dimensions;
    }

    /** @return string[] the shipment options the merchant switched on */
    public function getMerchantOptions(): array
    {
        return $this->merchantOptions;
    }

    /** The stored type plus its resolution, for a caller that must tell unrecognised from absent. */
    public function deliveryTypeValue(): TypeValue
    {
        return $this->deliveryType;
    }

    /** @see deliveryTypeValue() */
    public function packageTypeValue(): TypeValue
    {
        return $this->packageType;
    }

    public function isPickup(): bool
    {
        return DeliveryType::PICKUP_NAME === $this->deliveryType->name();
    }

    /**
     * Key order is part of the persisted format. Do not rearrange. The merchant's keys are appended
     * only when set, so an order without them keeps the exact shape it had.
     */
    public function toArray(): array
    {
        $array = [
            'carrier'         => $this->getCarrier(),
            'date'            => $this->getDate(),
            'deliveryType'    => $this->getDeliveryType(),
            'packageType'     => $this->getPackageType(),
            'isPickup'        => $this->isPickup(),
            'pickupLocation'  => null === $this->pickupLocation ? null : $this->pickupLocation->toArray(),
            'shipmentOptions' => null === $this->shipmentOptions ? null : $this->shipmentOptions->toArray(),
        ];

        if (null !== $this->labelAmount) {
            $array['labelAmount'] = $this->labelAmount;
        }

        if (null !== $this->digitalStampWeight) {
            $array['digitalStampWeight'] = $this->digitalStampWeight;
        }

        if ([] !== $this->dimensions) {
            $array['physicalProperties'] = $this->dimensions;
        }

        if ([] !== $this->merchantOptions) {
            $array['merchantOptions'] = $this->merchantOptions;
        }

        return $array;
    }
}
