<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ModelInterface;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesMoney;

/**
 * The insurance option's bounds, in whole euros.
 *
 * The API answers in **cents**, the module stores **euros**. This is the only place that converts:
 * a missing division by 100 insures a parcel for a hundredth of the amount, and nothing throws.
 * Zero is not in the range: `isRequired` alone decides whether "no insurance" is allowed.
 */
final class InsuranceRange
{
    private int  $min;
    private int  $max;
    private bool $required;

    private function __construct(int $min, int $max, bool $required)
    {
        $this->min      = $min;
        $this->max      = $max;
        $this->required = $required;
    }

    /**
     * Make the range, or null when the option is absent or has no maximum of at least one euro.
     * On null the caller must fall open and not refuse insurance.
     *
     * @param ModelInterface|null $option the insurance option as CapabilitySet::optionValue() returns it
     */
    public static function fromOptionValue(?ModelInterface $option): ?self
    {
        if (null === $option || ! method_exists($option, 'getMax') || ! method_exists($option, 'getMin')) {
            return null;
        }

        $max = self::cents($option->getMax());

        if (null === $max) {
            return null;
        }

        // A min that is present but has no amount voids the range, exactly as a bad max does.
        // Reading it as zero would let allows(0) pass an isRequired contract uninsured.
        if (null !== $option->getMin() && null === self::cents($option->getMin())) {
            return null;
        }

        // Round inwards, so a fractional bound can never widen the range: a min of 1050 cents
        // permits €11, not €10.
        $minCents = self::cents($option->getMin()) ?? 0;
        $min      = (int) ceil($minCents / 100);
        $maximum  = (int) floor($max / 100);

        // A maximum under one euro floors to zero, and a [0,0] range makes clamp() answer zero for
        // every amount: the uninsured parcel clamp() must never produce.
        if ($maximum < 1 || $min > $maximum) {
            return null;
        }

        return new self($min, $maximum, true === $option->getIsRequired());
    }

    /** Get the lowest insured amount, in whole euros. */
    public function min(): int
    {
        return $this->min;
    }

    /** Get the highest insured amount, in whole euros. */
    public function max(): int
    {
        return $this->max;
    }

    /** Check whether the contract makes insurance compulsory for this carrier. */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /** Check whether the amount is within the bounds, both included. allows() also accepts zero. */
    public function contains(int $euros): bool
    {
        return $euros >= $this->min && $euros <= $this->max;
    }

    /** Check whether a merchant may enter this amount: the range, plus zero ("none") when optional. */
    public function allows(int $euros): bool
    {
        return (0 === $euros && ! $this->required) || $this->contains($euros);
    }

    /** Get the lowest enterable amount, for a form control that can only express one span. */
    public function lowestAccepted(): int
    {
        return $this->required ? $this->min : 0;
    }

    /** Clamp the amount into the range. Never zero: above the maximum becomes the maximum. */
    public function clamp(int $euros): int
    {
        return max($this->min, min($this->max, $euros));
    }

    /**
     * Only the Money shape. A bare number states no scale, and guessing one is how an amount
     * silently becomes a hundredth of itself. The SDK reads a bare number as Money without an amount.
     * The SDK already cast the amount to int, so "100000oops" arrives as 100000: this checks only presence.
     */
    private static function cents($money): ?int
    {
        $amount = $money instanceof RefTypesMoney ? $money->getAmount() : null;

        return is_int($amount) ? $amount : null;
    }
}
