<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

/**
 * The insurance option's bounds, in whole euros.
 *
 * The API answers in **cents**, while every stored setting and every module value object is in
 * **euros** — a missing division by 100 insures a parcel for a hundredth of what the merchant
 * configured, and nothing throws. That conversion lives here and nowhere else. (The module's own
 * REST v1 response uses a third scale, integer-micro; that one is ShipmentOptionsTransformer's.)
 *
 * Bounds come from the flat `min` / `max` / `default` properties. The deprecated nested
 * `insuredAmount` wrapper is not read.
 *
 * Zero is not part of the range and is governed separately, by `isRequired`: a contract that does not
 * require insurance accepts "none", a contract that requires it does not. A minimum above zero bounds
 * what an insured parcel may be insured for; it does not by itself make insurance compulsory.
 */
final class InsuranceRange
{
    private int  $min;
    private int  $max;
    private ?int $default;
    private bool $required;

    private function __construct(int $min, int $max, ?int $default, bool $required)
    {
        $this->min      = $min;
        $this->max      = $max;
        $this->default  = $default;
        $this->required = $required;
    }

    /**
     * Null when the option is absent, names no maximum, or names a maximum below one euro — the
     * caller then has no bound to enforce and must fall open rather than refuse insurance
     * (FR-000010).
     *
     * @param array<string,mixed>|null $raw the option value as OptionSet::valueFor() returns it
     */
    public static function fromOptionValue(?array $raw): ?self
    {
        if (empty($raw)) {
            return null;
        }

        $max = self::centsIn($raw, 'max');

        if (null === $max) {
            return null;
        }

        // Round inwards, so a fractional bound can never widen the range: a min of 1050 cents
        // permits €11, not €10.
        $minCents = self::centsIn($raw, 'min') ?? 0;
        $min      = (int) ceil($minCents / 100);
        $maximum  = (int) floor($max / 100);

        // A maximum under one euro floors to zero, and a [0,0] range makes clamp() answer zero for
        // every amount — the uninsured parcel clamp() must never produce.
        if ($maximum < 1 || $min > $maximum) {
            return null;
        }

        $defaultCents = self::centsIn($raw, 'default');

        return new self(
            $min,
            $maximum,
            null === $defaultCents ? null : (int) round($defaultCents / 100),
            true === ($raw['isRequired'] ?? false)
        );
    }

    public function min(): int
    {
        return $this->min;
    }

    public function max(): int
    {
        return $this->max;
    }

    /** The amount the account suggests, when it names one. */
    public function default(): ?int
    {
        return $this->default;
    }

    /** Whether the contract makes insurance compulsory for this carrier. */
    public function isRequired(): bool
    {
        return $this->required;
    }

    public function contains(int $euros): bool
    {
        return $euros >= $this->min && $euros <= $this->max;
    }

    /**
     * Whether a merchant may enter this amount. The permitted set is the range, plus zero when
     * insurance is optional — zero being "no insurance", not an insured amount of nothing.
     */
    public function allows(int $euros): bool
    {
        return (0 === $euros && ! $this->required) || $this->contains($euros);
    }

    /** The lowest enterable amount, for a form control that can only express one span. */
    public function lowestAccepted(): int
    {
        return $this->required ? $this->min : 0;
    }

    /**
     * Never zeroes: an amount above the maximum becomes the maximum, not "uninsured". Shipping a
     * parcel uninsured because a contract narrowed is the outcome FR-000009 criterion 4 forbids.
     */
    public function clamp(int $euros): int
    {
        return max($this->min, min($this->max, $euros));
    }

    /** @param array<string,mixed> $source */
    private static function centsIn(array $source, string $key): ?int
    {
        $money = $source[$key] ?? null;

        // Only the Money shape. A bare number states no scale, and guessing one is how an amount
        // silently becomes a hundredth of itself.
        return is_array($money) && isset($money['amount']) && is_numeric($money['amount'])
            ? (int) $money['amount']
            : null;
    }
}
