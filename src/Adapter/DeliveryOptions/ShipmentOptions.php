<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter\DeliveryOptions;

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * One shipment's options, as stored. ShipmentOptionsResolver decides them; this only holds them.
 *
 * A null field means 'not stored', which is not false. The null survives into toArray(), which is
 * persisted on the order.
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

    private function __construct(array $values)
    {
        foreach (self::keys() as $key) {
            $this->values[$key] = $values[$key] ?? null;
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
     * what an absent option means. This one takes the values as they are.
     */
    public static function of(array $values): self
    {
        return new self($values);
    }

    /**
     * The old checkout carried only these four. The rest stay null, not false: it could not say.
     * Its options are stored as booleans, so a charged option also shows in the shipping method title.
     */
    public static function fromLegacyCheckoutData(array $options): self
    {
        return new self(
            [
                ShipmentOption::SIGNATURE         => isset($options['signature']) ? (bool) $options['signature'] : null,
                ShipmentOption::ONLY_RECIPIENT    => isset($options['only_recipient']) ? (bool) $options['only_recipient'] : null,
                ShipmentOption::INSURANCE         => $options['insurance'] ?? null,
                ShipmentOption::PRIORITY_DELIVERY => isset($options['priority_delivery']) ? (bool) $options['priority_delivery'] : null,
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

    /** A shipment option by its ShipmentOption name; null is inherit. */
    public function option(string $name): ?bool
    {
        $value = $this->values[$name] ?? null;

        return null === $value ? null : (bool) $value;
    }

    /** Get the insured amount in whole euros, not cents. Null is inherit. */
    public function getInsurance(): ?int
    {
        return $this->values[ShipmentOption::INSURANCE];
    }

    public function getLabelDescription(): ?string
    {
        return $this->values[self::LABEL_DESCRIPTION];
    }

    public function toArray(): array
    {
        return $this->values;
    }
}
