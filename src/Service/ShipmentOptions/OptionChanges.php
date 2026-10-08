<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ShipmentOptions;

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * The shipment option fields a merchant changed in the options modal. A null field is unchanged.
 *
 * The modal posts only the fields the merchant touched, so an absent field must keep inheriting.
 */
final class OptionChanges
{
    private ?string $carrier;
    private ?string $packageType;
    private ?int    $insurance;
    private ?int    $labelAmount;
    private ?int    $digitalStampWeight;

    /** @var array<string,bool> */
    private array $options;

    /** A Y-m-d date, self::NO_DELIVERY_DATE, or null for unchanged. */
    private ?string $deliveryDate;

    /** @var array<string,int> length, width or height in cm; 0 removes it */
    private array $dimensions;

    public const NO_DELIVERY_DATE = 'none';

    /** Each label is a track and a shipment at export, so the amount is capped. */
    public const MAX_LABEL_AMOUNT = 10;

    private function __construct(
        ?string $carrier,
        ?string $packageType,
        array   $options,
        ?int    $insurance,
        ?int    $labelAmount,
        ?int    $digitalStampWeight,
        ?string $deliveryDate,
        array   $dimensions
    ) {
        $this->carrier            = $carrier;
        $this->packageType        = $packageType;
        $this->options            = $options;
        $this->insurance          = $insurance;
        $this->labelAmount        = $labelAmount;
        $this->digitalStampWeight = $digitalStampWeight;
        $this->deliveryDate       = $deliveryDate;
        $this->dimensions         = $dimensions;
    }

    /** Reads the modal's `changes` parameter. A malformed name or value is dropped, not guessed. */
    public static function fromRequest(array $changes): self
    {
        $options = [];

        foreach ((array) ($changes['options'] ?? []) as $name => $value) {
            if (ShipmentOption::isOptionName($name)) {
                $options[$name] = '1' === (string) $value;
            }
        }

        $dimensions = [];

        foreach (DeliveryOptions::DIMENSIONS as $dimension) {
            $value = self::int($changes[$dimension] ?? null);

            if (null !== $value) {
                $dimensions[$dimension] = $value;
            }
        }

        return new self(
            self::name($changes['carrier'] ?? null),
            self::name($changes['package_type'] ?? null),
            $options,
            self::int($changes['insurance'] ?? null),
            self::boundedLabelAmount($changes['label_amount'] ?? null),
            self::int($changes['digital_stamp_weight'] ?? null),
            self::date($changes['delivery_date'] ?? null),
            $dimensions
        );
    }

    public function carrier(): ?string
    {
        return $this->carrier;
    }

    public function packageType(): ?string
    {
        return $this->packageType;
    }

    /** @return array<string,bool> */
    public function options(): array
    {
        return $this->options;
    }

    public function insurance(): ?int
    {
        return $this->insurance;
    }

    public function labelAmount(): ?int
    {
        return $this->labelAmount;
    }

    public function digitalStampWeight(): ?int
    {
        return $this->digitalStampWeight;
    }

    /** A Y-m-d date, self::NO_DELIVERY_DATE to remove it, or null for unchanged. */
    public function deliveryDate(): ?string
    {
        return $this->deliveryDate;
    }

    /** @return array<string,int> the changed length, width or height in cm; 0 removes it */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    public function isEmpty(): bool
    {
        return null === $this->carrier
               && null === $this->packageType
               && [] === $this->options
               && null === $this->insurance
               && null === $this->labelAmount
               && null === $this->digitalStampWeight
               && null === $this->deliveryDate
               && [] === $this->dimensions;
    }

    private static function name($value): ?string
    {
        return ShipmentOption::isOptionName($value) ? $value : null;
    }

    private static function date($value): ?string
    {
        if (self::NO_DELIVERY_DATE === $value) {
            return self::NO_DELIVERY_DATE;
        }

        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    private static function boundedLabelAmount($value): ?int
    {
        $amount = self::int($value);

        return null !== $amount && $amount >= 1 && $amount <= self::MAX_LABEL_AMOUNT ? $amount : null;
    }

    private static function int($value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }
}
