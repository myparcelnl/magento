<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter\DeliveryOptions;

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * One shipment's options, as stored. ShipmentOptionsResolver decides them; this only holds them.
 *
 * Stored on an order, null is inherit, true is on and false is off: false beats the carrier
 * setting. A null field means 'not stored', which is not false. toArray() holds every keys() entry,
 * null included, because it is persisted on the order.
 *
 * The named constructors read different stored shapes and deliberately disagree on defaults.
 */
final class ShipmentOptions
{
    private const DEFAULT_INSURANCE = 0;

    /** A stored key that is not a shipment option: the label text. */
    public const LABEL_DESCRIPTION = 'label_description';

    /** The stored shipment options that are not toggles. */
    private const OTHER_OPTIONS = [ShipmentOption::INSURANCE, ShipmentOption::SAME_DAY_DELIVERY];

    /** @var array<string,mixed> every keys() entry; null where nothing was stored */
    private $values = [];

    /** @var array<string,bool|null> options only capabilities named, after every keys() entry */
    private $discovered = [];

    private function __construct(array $values, bool $keepDiscovered = false)
    {
        foreach (self::keys() as $key) {
            $this->values[$key] = $values[$key] ?? null;
        }

        if (! $keepDiscovered) {
            return;
        }

        foreach (array_diff_key($values, $this->values) as $key => $value) {
            $this->discovered[(string) $key] = null === $value ? null : (bool) $value;
        }
    }

    /** Every stored key that is a shipment option. */
    public static function optionKeys(): array
    {
        return array_merge(ShipmentOption::having('toggle'), self::OTHER_OPTIONS);
    }

    private static function keys(): array
    {
        return array_merge(self::optionKeys(), [self::LABEL_DESCRIPTION]);
    }

    /**
     * A set that needs no reading of a stored shape: the resolver's own output, or nothing at all.
     *
     * fromLegacyCheckoutData() and fromMagentoOptions() exist because those two shapes disagree on
     * what an absent option means. This one takes the values as they are. The checkout's data
     * passes through here into a persisted format, so a key keys() does not name stays only when it
     * is an option a merchant can save: a snake_case name with a bool or null value.
     */
    public static function of(array $values): self
    {
        $saved = array_filter(
            $values,
            static fn($value, $key): bool => ShipmentOption::isOptionName($key) && (null === $value || is_bool($value)),
            ARRAY_FILTER_USE_BOTH
        );

        return new self(array_replace($saved, array_intersect_key($values, array_flip(self::keys()))), true);
    }

    /** ShipmentOptionsResolver's answer, which keeps the options only capabilities named. */
    public static function resolved(array $values): self
    {
        return new self($values, true);
    }

    /**
     * The old checkout carried only these four. The rest stay null, not false: it could not say.
     * Its false and 0 meant 'not chosen', so they read as inherit, not as off.
     */
    public static function fromLegacyCheckoutData(array $options): self
    {
        return new self(
            [
                ShipmentOption::SIGNATURE         => ($options['signature'] ?? null) ? true : null,
                ShipmentOption::ONLY_RECIPIENT    => ($options['only_recipient'] ?? null) ? true : null,
                ShipmentOption::INSURANCE         => ($options['insurance'] ?? null) ?: null,
                ShipmentOption::PRIORITY_DELIVERY => ($options['priority_delivery'] ?? null) ? true : null,
            ]
        );
    }

    /**
     * Admin New Shipment form or mass action. Absent means 'not chosen' here, not 'unknown', so every
     * checkbox flattens to false and insurance to 0. What the form never carries stays null.
     */
    public static function fromMagentoOptions(array $options): self
    {
        $values = [ShipmentOption::INSURANCE => (int) ($options[ShipmentOption::INSURANCE] ?? self::DEFAULT_INSURANCE)];

        foreach (ShipmentOption::having('toggle') as $option) {
            $values[$option] = (bool) ($options[$option] ?? false);
        }

        return new self($values);
    }

    /** A shipment option by its ShipmentOption name, also one only capabilities named; null is inherit. */
    public function option(string $name): ?bool
    {
        $value = $this->values[$name] ?? $this->discovered[$name] ?? null;

        return null === $value ? null : (bool) $value;
    }

    public function getInsurance(): ?int
    {
        return $this->values[ShipmentOption::INSURANCE];
    }

    public function getLabelDescription(): ?string
    {
        return $this->values[self::LABEL_DESCRIPTION];
    }

    /**
     * The options only capabilities named, which keys() does not list.
     *
     * @return array<string,bool|null>
     */
    public function discovered(): array
    {
        return $this->discovered;
    }

    public function toArray(): array
    {
        return $this->values + $this->discovered;
    }
}
