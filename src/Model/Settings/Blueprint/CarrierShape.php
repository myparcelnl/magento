<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Blueprint;

use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * What one carrier's settings section is built from: its package types, delivery types and options,
 * already sorted into the group each option belongs to.
 *
 * Read from capabilities only: when they could not be read, no carrier has a shape at all.
 */
final class CarrierShape
{
    /** @var string[] */
    private array $packageTypes;

    /** @var string[] */
    private array $deliveryTypes;

    /** @var string[] */
    private array $defaultOptions;

    /** @var string[] */
    private array $checkoutOptions;

    /** @var string[] */
    private array $mailboxOptions;

    private function __construct(
        array $packageTypes,
        array $deliveryTypes,
        array $defaultOptions,
        array $checkoutOptions,
        array $mailboxOptions
    ) {
        $this->packageTypes    = $packageTypes;
        $this->deliveryTypes   = $deliveryTypes;
        $this->defaultOptions  = $defaultOptions;
        $this->checkoutOptions = $checkoutOptions;
        $this->mailboxOptions  = $mailboxOptions;
    }

    public static function fromCapabilities(CapabilitySet $capabilities, string $carrier): self
    {
        $packageTypes = $capabilities->packageTypesFor($carrier);
        $options      = $capabilities->optionsFor($carrier);
        $mailbox      = in_array(PackageType::MAILBOX_NAME, $packageTypes, true)
            ? $capabilities->optionsFor($carrier, PackageType::MAILBOX_NAME)
            : [];

        return new self(
            $packageTypes,
            $capabilities->deliveryTypesFor($carrier),
            array_values(array_diff($options, Catalogue::MAILBOX_OPTIONS)),
            array_values(array_intersect(Catalogue::CHECKOUT_OPTIONS, $options)),
            array_values(array_intersect(Catalogue::MAILBOX_OPTIONS, $mailbox))
        );
    }

    public function hasPackageType(string $packageType): bool
    {
        return in_array($packageType, $this->packageTypes, true);
    }

    public function hasDeliveryType(string $deliveryType): bool
    {
        return in_array($deliveryType, $this->deliveryTypes, true);
    }

    /** @return string[] the options with an Automate toggle, insurance included */
    public function defaultOptions(): array
    {
        return $this->defaultOptions;
    }

    /** @return string[] */
    public function checkoutOptions(): array
    {
        return $this->checkoutOptions;
    }

    /** @return string[] */
    public function mailboxOptions(): array
    {
        return $this->mailboxOptions;
    }

    public function hasInsurance(): bool
    {
        return in_array(ShipmentOption::INSURANCE, $this->defaultOptions, true);
    }

    /**
     * What the delivery titles are offered for: each package type, delivery type and option, as
     * `kind:name`.
     *
     * @return string[]
     */
    public function facts(): array
    {
        $facts = [];

        foreach ($this->packageTypes as $packageType) {
            $facts[] = Catalogue::FACT_PACKAGE_TYPE . $packageType;
        }

        foreach ($this->deliveryTypes as $deliveryType) {
            $facts[] = Catalogue::FACT_DELIVERY_TYPE . $deliveryType;
        }

        foreach (array_merge($this->defaultOptions, $this->checkoutOptions, $this->mailboxOptions) as $option) {
            $facts[] = Catalogue::FACT_OPTION . $option;
        }

        return array_values(array_unique($facts));
    }
}
