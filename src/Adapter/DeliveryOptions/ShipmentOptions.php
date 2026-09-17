<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter\DeliveryOptions;

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * One shipment's options, as stored. ShipmentOptionsResolver decides them; this only holds them.
 *
 * A null field means 'not stored', which is not false. The null survives into toArray(), which is a
 * persisted format whose key order is part of the contract.
 *
 * The named constructors read different stored shapes and deliberately disagree on defaults.
 */
final class ShipmentOptions
{
    private const DEFAULT_INSURANCE = 0;

    /**
     * Every key, in the order toArray() must emit them: that order is a persisted format.
     *
     * label_description and extra_assurance are not shipment options, which is why they are literals
     * rather than ShipmentOption constants. Nothing in the module decides or reads extra_assurance;
     * it stays because dropping a key from a persisted format is a data question, not a code one.
     */
    private const KEYS
        = [
            ShipmentOption::SIGNATURE,
            ShipmentOption::COLLECT,
            ShipmentOption::RECEIPT_CODE,
            ShipmentOption::INSURANCE,
            ShipmentOption::AGE_CHECK,
            ShipmentOption::ONLY_RECIPIENT,
            ShipmentOption::RETURN,
            ShipmentOption::SAME_DAY_DELIVERY,
            ShipmentOption::LARGE_FORMAT,
            'label_description',
            ShipmentOption::HIDE_SENDER,
            'extra_assurance',
            ShipmentOption::PRIORITY_DELIVERY,
        ];

    /** @var array<string,mixed> every KEYS entry, in that order; null where nothing was stored */
    private $values = [];

    private function __construct(array $values)
    {
        foreach (self::KEYS as $key) {
            $this->values[$key] = $values[$key] ?? null;
        }
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

    /** The old checkout carried only these four. The rest stay null, not false: it could not say. */
    public static function fromLegacyCheckoutData(array $options): self
    {
        return new self(
            [
                ShipmentOption::SIGNATURE         => $options['signature'] ?? null,
                ShipmentOption::ONLY_RECIPIENT    => $options['only_recipient'] ?? null,
                ShipmentOption::INSURANCE         => $options['insurance'] ?? null,
                ShipmentOption::PRIORITY_DELIVERY => $options['priority_delivery'] ?? null,
            ]
        );
    }

    /**
     * Admin New Shipment form or mass action. Absent means 'not chosen' here, not 'unknown', so it
     * flattens to false and insurance to 0. The four options this form never carries stay null.
     */
    public static function fromMagentoOptions(array $options): self
    {
        return new self(
            [
                ShipmentOption::SIGNATURE         => (bool) ($options[ShipmentOption::SIGNATURE] ?? false),
                ShipmentOption::COLLECT           => (bool) ($options[ShipmentOption::COLLECT] ?? false),
                ShipmentOption::RECEIPT_CODE      => (bool) ($options[ShipmentOption::RECEIPT_CODE] ?? false),
                ShipmentOption::ONLY_RECIPIENT    => (bool) ($options[ShipmentOption::ONLY_RECIPIENT] ?? false),
                ShipmentOption::LARGE_FORMAT      => (bool) ($options[ShipmentOption::LARGE_FORMAT] ?? false),
                ShipmentOption::AGE_CHECK         => (bool) ($options[ShipmentOption::AGE_CHECK] ?? false),
                ShipmentOption::RETURN            => (bool) ($options[ShipmentOption::RETURN] ?? false),
                ShipmentOption::PRIORITY_DELIVERY => (bool) ($options[ShipmentOption::PRIORITY_DELIVERY] ?? false),
                ShipmentOption::INSURANCE         => (int) ($options[ShipmentOption::INSURANCE] ?? self::DEFAULT_INSURANCE),
            ]
        );
    }

    public function hasSignature(): ?bool
    {
        return $this->values[ShipmentOption::SIGNATURE];
    }

    public function hasReceiptCode(): ?bool
    {
        return $this->values[ShipmentOption::RECEIPT_CODE];
    }

    public function hasCollect(): ?bool
    {
        return $this->values[ShipmentOption::COLLECT];
    }

    public function hasOnlyRecipient(): ?bool
    {
        return $this->values[ShipmentOption::ONLY_RECIPIENT];
    }

    public function hasAgeCheck(): ?bool
    {
        return $this->values[ShipmentOption::AGE_CHECK];
    }

    public function hasLargeFormat(): ?bool
    {
        return $this->values[ShipmentOption::LARGE_FORMAT];
    }

    public function hasHideSender(): ?bool
    {
        return $this->values[ShipmentOption::HIDE_SENDER];
    }

    /** Return the package if the recipient is not home. */
    public function hasReturn(): ?bool
    {
        return $this->values[ShipmentOption::RETURN];
    }

    public function hasSameDayDelivery(): ?bool
    {
        return $this->values[ShipmentOption::SAME_DAY_DELIVERY];
    }

    public function hasPriorityDelivery(): ?bool
    {
        return $this->values[ShipmentOption::PRIORITY_DELIVERY];
    }

    public function getInsurance(): ?int
    {
        return $this->values[ShipmentOption::INSURANCE];
    }

    public function getLabelDescription(): ?string
    {
        return $this->values['label_description'];
    }

    /** @see self::KEYS — the order is part of the persisted format. */
    public function toArray(): array
    {
        return $this->values;
    }
}
