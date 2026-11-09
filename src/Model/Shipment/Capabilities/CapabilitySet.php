<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use MyParcelNL\Magento\Facade\Logger;

/**
 * A parsed capabilities response: what one MyParcel account may do for one request.
 *
 * A (carrier, package type) question gets the union of every matching result; a null package type
 * means "any". permissive() is the fail-open state, not an empty response: never use it to mean
 * that an account has nothing.
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

        foreach ($results as $index => $result) {
            if (! is_array($result)) {
                // Logged, so a changed API answer does not look like a lost carrier.
                Logger::notice(sprintf(
                    'MyParcel capabilities: entry %s is a %s, not an object, skipped',
                    (string) $index,
                    gettype($result)
                ));

                continue;
            }

            $capabilities[] = CarrierCapability::fromResult($result);
        }

        return new self($capabilities, false);
    }

    /**
     * Make the set from a contract-definitions response, which the same parser reads.
     *
     * Contract definitions carry no country, so do not read this set as a shipment answer.
     */
    public static function fromContractDefinitionItems(array $items): self
    {
        return self::fromApiResults($items);
    }

    /** Make the set that allows everything, for when no answer is available. */
    public static function permissive(): self
    {
        return new self([], true);
    }

    /** Check whether this set allows everything because no answer was available. */
    public function isPermissive(): bool
    {
        return $this->permissive;
    }

    /**
     * Get the module carrier names the account has a contract for. Empty when permissive.
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

    /** Permissive answers yes, for the same reason hasOption() does. */
    public function hasPackageType(string $carrier, string $packageType): bool
    {
        return $this->permissive || in_array($packageType, $this->packageTypesFor($carrier), true);
    }

    /** Permissive answers yes, for the same reason hasOption() does. */
    public function hasDeliveryType(string $carrier, ?string $packageType, string $deliveryType): bool
    {
        return $this->permissive
               || in_array($deliveryType, $this->deliveryTypesFor($carrier, $packageType), true);
    }

    /** Permissive answers yes: offer the option and let the API decide. */
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
     * Get the option's own properties, from the first matching result that carries it.
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
     * Get every v2 value the module could not translate, deduplicated by kind.
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
