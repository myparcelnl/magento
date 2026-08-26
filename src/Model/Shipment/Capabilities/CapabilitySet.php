<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

/**
 * A parsed capabilities response: what one MyParcel account may do for one request shape.
 *
 * A result matches a carrier and a set of package types, so a question about (carrier, package
 * type) is answered by the union of every result that matches both. A null package type means
 * "any", which is how a page that has not chosen one yet asks.
 *
 * permissive() is the fail-open state and is not the same as an empty response: it answers yes to
 * every option and reports no carriers, so a caller can tell "the account cannot do this" from "we
 * could not find out" through isPermissive(). Never construct it to mean an account has nothing.
 */
final class CapabilitySet
{
    /** @var CarrierCapability[] */
    private array $capabilities;

    private bool $permissive;

    private function __construct(array $capabilities, bool $permissive)
    {
        $this->capabilities = $capabilities;
        $this->permissive   = $permissive;
    }

    public static function fromApiResults(array $results): self
    {
        $capabilities = [];

        foreach ($results as $result) {
            if (is_array($result)) {
                $capabilities[] = CarrierCapability::fromResult($result);
            }
        }

        return new self($capabilities, false);
    }

    public static function permissive(): self
    {
        return new self([], true);
    }

    public function isPermissive(): bool
    {
        return $this->permissive;
    }

    /**
     * Module carrier names the account has a contract for. Empty when permissive — the caller falls
     * back to every configured carrier rather than showing none.
     *
     * @return string[]
     */
    public function carriers(): array
    {
        $carriers = [];

        foreach ($this->capabilities as $capability) {
            $carrier = $capability->carrier();

            if (null !== $carrier && ! in_array($carrier, $carriers, true)) {
                $carriers[] = $carrier;
            }
        }

        return $carriers;
    }

    /** @return string[] */
    public function packageTypesFor(string $carrier): array
    {
        return $this->union($carrier, null, static function (CarrierCapability $c): array {
            return $c->packageTypes();
        });
    }

    /** @return string[] */
    public function deliveryTypesFor(string $carrier, ?string $packageType = null): array
    {
        return $this->union($carrier, $packageType, static function (CarrierCapability $c): array {
            return $c->deliveryTypes();
        });
    }

    /** @return string[] module option names */
    public function optionsFor(string $carrier, ?string $packageType = null): array
    {
        return $this->union($carrier, $packageType, static function (CarrierCapability $c): array {
            return $c->options()->moduleNames();
        });
    }

    /** Permissive answers yes: offer the option and let the API decide (FR-000010). */
    public function hasOption(string $carrier, ?string $packageType, string $option): bool
    {
        if ($this->permissive) {
            return true;
        }

        foreach ($this->matching($carrier, $packageType) as $capability) {
            if ($capability->options()->has($option)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The option's own properties, from the first matching result that carries it. Phase 5 reads
     * insurance min/max through this.
     *
     * @return array<string,mixed>|null
     */
    public function optionValue(string $carrier, ?string $packageType, string $option): ?array
    {
        foreach ($this->matching($carrier, $packageType) as $capability) {
            $value = $capability->options()->valueFor($option);

            if (null !== $value) {
                return $value;
            }
        }

        return null;
    }

    /** Null when unknown, including permissive. A caller that must choose picks the safe branch. */
    public function colloMaxFor(string $carrier, ?string $packageType = null): ?int
    {
        $max = null;

        foreach ($this->matching($carrier, $packageType) as $capability) {
            $colloMax = $capability->colloMax();

            if (null !== $colloMax) {
                $max = null === $max ? $colloMax : max($max, $colloMax);
            }
        }

        return $max;
    }

    /**
     * Every v2 value the module could not translate, deduplicated by kind. The Repository logs this
     * once per fetch — it is the early-warning signal that the module needs updating.
     *
     * @return array<string,string[]>
     */
    public function unknownValues(): array
    {
        $unknown = ['carrier' => [], 'packageType' => [], 'deliveryType' => [], 'option' => []];

        foreach ($this->capabilities as $capability) {
            foreach ($capability->unknownValues() as $kind => $values) {
                $unknown[$kind] = array_merge($unknown[$kind], $values);
            }
        }

        return array_map(static function (array $values): array {
            return array_values(array_unique($values));
        }, $unknown);
    }

    /**
     * @param  callable(CarrierCapability):string[] $extract
     * @return string[]
     */
    private function union(string $carrier, ?string $packageType, callable $extract): array
    {
        $values = [];

        foreach ($this->matching($carrier, $packageType) as $capability) {
            $values = array_merge($values, $extract($capability));
        }

        return array_values(array_unique($values));
    }

    /** @return CarrierCapability[] */
    private function matching(string $carrier, ?string $packageType): array
    {
        $matching = [];

        foreach ($this->capabilities as $capability) {
            if ($carrier !== $capability->carrier()) {
                continue;
            }

            if (null !== $packageType && ! $capability->hasPackageType($packageType)) {
                continue;
            }

            $matching[] = $capability;
        }

        return $matching;
    }
}
