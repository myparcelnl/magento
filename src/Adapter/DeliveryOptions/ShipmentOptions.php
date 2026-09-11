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

    /** @var bool|null */
    private $signature;

    /** @var bool|null */
    private $collect;

    /** @var bool|null */
    private $receiptCode;

    /** @var int|null */
    private $insurance;

    /** @var bool|null */
    private $ageCheck;

    /** @var bool|null */
    private $onlyRecipient;

    /** @var bool|null */
    private $return;

    /** @var bool|null */
    private $sameDayDelivery;

    /** @var bool|null */
    private $largeFormat;

    /** @var string|null */
    private $labelDescription;

    /** @var bool|null */
    private $hideSender;

    /** @var bool|null */
    private $extraAssurance;

    /** @var bool|null */
    private $priorityDelivery;

    private function __construct(array $values)
    {
        $this->signature        = $values['signature'] ?? null;
        $this->collect          = $values['collect'] ?? null;
        $this->receiptCode      = $values['receipt_code'] ?? null;
        $this->insurance        = $values['insurance'] ?? null;
        $this->ageCheck         = $values['age_check'] ?? null;
        $this->onlyRecipient    = $values['only_recipient'] ?? null;
        $this->return           = $values['return'] ?? null;
        $this->sameDayDelivery  = $values['same_day_delivery'] ?? null;
        $this->largeFormat      = $values['large_format'] ?? null;
        $this->labelDescription = $values['label_description'] ?? null;
        $this->hideSender       = $values['hide_sender'] ?? null;
        $this->extraAssurance   = $values['extra_assurance'] ?? null;
        $this->priorityDelivery = $values['priority_delivery'] ?? null;
    }

    /** Every option is read; an absent one stays null. */
    public static function fromCheckoutData(array $options): self
    {
        return new self($options);
    }

    /** The old checkout carried only these four. The rest stay null, not false: it could not say. */
    public static function fromLegacyCheckoutData(array $options): self
    {
        return new self(
            [
                'signature'         => $options['signature'] ?? null,
                'only_recipient'    => $options['only_recipient'] ?? null,
                'insurance'         => $options['insurance'] ?? null,
                'priority_delivery' => $options['priority_delivery'] ?? null,
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
                'signature'         => (bool) ($options[ShipmentOption::SIGNATURE] ?? false),
                'collect'           => (bool) ($options[ShipmentOption::COLLECT] ?? false),
                'receipt_code'      => (bool) ($options[ShipmentOption::RECEIPT_CODE] ?? false),
                'only_recipient'    => (bool) ($options[ShipmentOption::ONLY_RECIPIENT] ?? false),
                'large_format'      => (bool) ($options[ShipmentOption::LARGE_FORMAT] ?? false),
                'age_check'         => (bool) ($options[ShipmentOption::AGE_CHECK] ?? false),
                'return'            => (bool) ($options[ShipmentOption::RETURN] ?? false),
                'priority_delivery' => (bool) ($options[ShipmentOption::PRIORITY_DELIVERY] ?? false),
                'insurance'         => (int) ($options[ShipmentOption::INSURANCE] ?? self::DEFAULT_INSURANCE),
            ]
        );
    }

    /**
     * A resolved set, so nothing is null by accident. extra_assurance is the exception: nothing in
     * the module decides or reads it.
     */
    public static function resolved(array $values): self
    {
        return new self($values);
    }

    /** No options stored. For a caller that wants an object to read rather than a null check. */
    public static function none(): self
    {
        return new self([]);
    }

    public function hasSignature(): ?bool
    {
        return $this->signature;
    }

    public function hasReceiptCode(): ?bool
    {
        return $this->receiptCode;
    }

    public function hasCollect(): ?bool
    {
        return $this->collect;
    }

    public function hasOnlyRecipient(): ?bool
    {
        return $this->onlyRecipient;
    }

    public function hasAgeCheck(): ?bool
    {
        return $this->ageCheck;
    }

    public function hasLargeFormat(): ?bool
    {
        return $this->largeFormat;
    }

    public function hasHideSender(): ?bool
    {
        return $this->hideSender;
    }

    public function hasExtraAssurance(): ?bool
    {
        return $this->extraAssurance;
    }

    /** Return the package if the recipient is not home. */
    public function hasReturn(): ?bool
    {
        return $this->return;
    }

    public function hasSameDayDelivery(): ?bool
    {
        return $this->sameDayDelivery;
    }

    public function hasPriorityDelivery(): ?bool
    {
        return $this->priorityDelivery;
    }

    public function getInsurance(): ?int
    {
        return $this->insurance;
    }

    public function getLabelDescription(): ?string
    {
        return $this->labelDescription;
    }

    /** Key order is part of the persisted format. Do not rearrange. */
    public function toArray(): array
    {
        return [
            'signature'         => $this->hasSignature(),
            'collect'           => $this->hasCollect(),
            'receipt_code'      => $this->hasReceiptCode(),
            'insurance'         => $this->getInsurance(),
            'age_check'         => $this->hasAgeCheck(),
            'only_recipient'    => $this->hasOnlyRecipient(),
            'return'            => $this->hasReturn(),
            'same_day_delivery' => $this->hasSameDayDelivery(),
            'large_format'      => $this->hasLargeFormat(),
            'label_description' => $this->getLabelDescription(),
            'hide_sender'       => $this->hasHideSender(),
            'extra_assurance'   => $this->hasExtraAssurance(),
            'priority_delivery' => $this->hasPriorityDelivery(),
        ];
    }
}
