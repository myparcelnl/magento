<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;

/**
 * One entry of a capabilities response's `results` array.
 *
 * Every v2 name it could not translate is kept in an `unknown*` list instead of being discarded, so
 * the Repository can log what the module does not yet understand.
 */
final class CarrierCapability
{
    private ?string $carrier;
    private string $v2Carrier;
    /** @var string[] */
    private array $packageTypes;
    /** @var string[] */
    private array $unknownPackageTypes;
    /** @var string[] */
    private array $deliveryTypes;
    /** @var string[] */
    private array $unknownDeliveryTypes;
    private OptionSet $options;
    private ?int $colloMax;

    private function __construct(
        ?string   $carrier,
        string    $v2Carrier,
        array     $packageTypes,
        array     $unknownPackageTypes,
        array     $deliveryTypes,
        array     $unknownDeliveryTypes,
        OptionSet $options,
        ?int      $colloMax
    )
    {
        $this->carrier              = $carrier;
        $this->v2Carrier            = $v2Carrier;
        $this->packageTypes         = $packageTypes;
        $this->unknownPackageTypes  = $unknownPackageTypes;
        $this->deliveryTypes        = $deliveryTypes;
        $this->unknownDeliveryTypes = $unknownDeliveryTypes;
        $this->options              = $options;
        $this->colloMax             = $colloMax;
    }

    public static function fromResult(array $result): self
    {
        // `carrier` is an enum on the wire, so a bare string. An object with a `name` is accepted
        // too rather than assumed away: the spec has moved before and this read must not fatal.
        $carrier   = $result['carrier'] ?? null;
        $v2Carrier = is_array($carrier) ? (string) ($carrier['name'] ?? '') : (string) $carrier;

        [$packageTypes, $unknownPackageTypes] = self::translate(
            $result['packageTypes'] ?? null,
            [PackageType::class, 'fromV2Name']
        );

        [$deliveryTypes, $unknownDeliveryTypes] = self::translate(
            $result['deliveryTypes'] ?? null,
            [DeliveryType::class, 'fromV2Name']
        );

        $collo = is_array($result['collo'] ?? null) ? ($result['collo']['max'] ?? null) : null;

        return new self(
            '' === $v2Carrier ? null : Carrier::fromV2Name($v2Carrier),
            $v2Carrier,
            $packageTypes,
            $unknownPackageTypes,
            $deliveryTypes,
            $unknownDeliveryTypes,
            OptionSet::fromArray(is_array($result['options'] ?? null) ? $result['options'] : []),
            null === $collo ? null : (int) $collo
        );
    }

    /**
     * @param  callable(string):?string $resolve
     * @return array{0: string[], 1: string[]} recognised names, then the raw values that were not
     */
    private static function translate($values, callable $resolve): array
    {
        $known   = [];
        $unknown = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (! is_string($value)) {
                continue;
            }

            $name = $resolve($value);
            null === $name ? $unknown[] = $value : $known[] = $name;
        }

        return [$known, $unknown];
    }

    public function carrier(): ?string
    {
        return $this->carrier;
    }

    public function v2Carrier(): string
    {
        return $this->v2Carrier;
    }

    public function hasPackageType(string $packageType): bool
    {
        return in_array($packageType, $this->packageTypes, true);
    }

    /** @return string[] */
    public function packageTypes(): array
    {
        return $this->packageTypes;
    }

    /** @return string[] */
    public function deliveryTypes(): array
    {
        return $this->deliveryTypes;
    }

    public function options(): OptionSet
    {
        return $this->options;
    }

    public function colloMax(): ?int
    {
        return $this->colloMax;
    }

    /** @return array{carrier: string[], packageType: string[], deliveryType: string[], option: string[]} */
    public function unknownValues(): array
    {
        return [
            'carrier'      => null === $this->carrier && '' !== $this->v2Carrier ? [$this->v2Carrier] : [],
            'packageType'  => $this->unknownPackageTypes,
            'deliveryType' => $this->unknownDeliveryTypes,
            'option'       => $this->options->unknownKeys(),
        ];
    }
}
