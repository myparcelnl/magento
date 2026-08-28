<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter\DeliveryOptions;

use InvalidArgumentException;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\Type\DeliveryTypeValue;
use MyParcelNL\Magento\Model\Shipment\Type\PackageTypeValue;
use MyParcelNL\Sdk\Support\Str;

/**
 * The delivery options stored on an order: carrier, date, delivery type, package type, shipment
 * options and, for a pickup, the location. Build one through DeliveryOptionsFactory.
 *
 * toArray() is a persisted and published format — quote data, and Magento's order REST API — so its
 * key order is part of the contract.
 *
 * The two types are value objects so an unrecognised one survives instead of being defaulted. The
 * plain getters answer with the name; the *Value() getters say whether it resolved.
 */
final class DeliveryOptions
{
    // NeedsQuoteProps and Carrier read these off an empty instance, so '' rather than null matters.
    private const DEFAULT_DATE          = '';
    private const DEFAULT_DELIVERY_TYPE = DeliveryType::STANDARD_NAME;

    /** @var string|null */
    private $carrier;

    /** @var string|null */
    private $date;

    /** @var \MyParcelNL\Magento\Model\Shipment\Type\DeliveryTypeValue */
    private $deliveryType;

    /** @var \MyParcelNL\Magento\Model\Shipment\Type\PackageTypeValue */
    private $packageType;

    /** @var \MyParcelNL\Magento\Adapter\DeliveryOptions\PickupLocation|null */
    private $pickupLocation;

    /** @var \MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions|null */
    private $shipmentOptions;

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
        ?PickupLocation $pickupLocation
    ) {
        $this->carrier         = $carrier;
        $this->date            = $date;
        $this->deliveryType    = DeliveryTypeValue::fromStored($deliveryType);
        $this->packageType     = PackageTypeValue::fromStored($packageType);
        $this->shipmentOptions = $shipmentOptions;
        $this->pickupLocation  = $pickupLocation;
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
            ShipmentOptions::fromCheckoutData($data['shipmentOptions'] ?? []),
            $pickup
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
            $pickup
        );
    }

    /**
     * The widget sends these two nested objects in camelCase, and a toArray() round trip writes them
     * back in snake_case, so both spellings exist in the database. The top level stays camelCase.
     *
     * Every named constructor calls this on its own input rather than trusting the caller to have
     * done it. Trusting the caller is what let fromOrderFallback() drop a pickup location silently
     * for six years (DR-24). Idempotent, so a second pass costs nothing.
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
            ShipmentOptions::none(),
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

    public function getDeliveryTypeId(): ?int
    {
        return $this->deliveryType->id();
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

    /** The stored type plus its resolution, for a caller that must tell unrecognised from absent. */
    public function deliveryTypeValue(): DeliveryTypeValue
    {
        return $this->deliveryType;
    }

    /** @see deliveryTypeValue() */
    public function packageTypeValue(): PackageTypeValue
    {
        return $this->packageType;
    }

    public function isPickup(): bool
    {
        return DeliveryType::PICKUP_NAME === $this->deliveryType->name();
    }

    /** Key order is part of the persisted format. Do not rearrange. */
    public function toArray(): array
    {
        return [
            'carrier'         => $this->getCarrier(),
            'date'            => $this->getDate(),
            'deliveryType'    => $this->getDeliveryType(),
            'packageType'     => $this->getPackageType(),
            'isPickup'        => $this->isPickup(),
            'pickupLocation'  => null === $this->pickupLocation ? null : $this->pickupLocation->toArray(),
            'shipmentOptions' => null === $this->shipmentOptions ? null : $this->shipmentOptions->toArray(),
        ];
    }
}
