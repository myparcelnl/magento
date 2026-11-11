<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ModelInterface;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefCapabilitiesContractDefinitionsResponseContractDefinitionsV2;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefCapabilitiesResponseCapabilityV2;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefCapabilitiesSharedCarrierV2;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentPackageTypeV2;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesDeliveryTypeV2;
use MyParcelNL\Sdk\Client\Generated\CoreApi\ObjectSerializer;
use Throwable;

/**
 * A parsed capabilities response: what one MyParcel account may do for one request.
 *
 * Reads the SDK's generated models, so the SDK decides which options exist. Never serialize a
 * result: a model with an enum value the SDK does not know throws on json_encode(). permissive()
 * is the fail-open state, not an empty response: never use it to mean that an account has nothing.
 */
final class CapabilitySet
{
    /** @var RefCapabilitiesResponseCapabilityV2[]|RefCapabilitiesContractDefinitionsResponseContractDefinitionsV2[] */
    private array $results;

    private bool $permissive;

    private function __construct(array $results, bool $permissive)
    {
        $this->results    = $results;
        $this->permissive = $permissive;
    }

    /** Make the set from the raw `results` of a capabilities response. A bad entry is logged and skipped. */
    public static function fromApiResults(array $results): self
    {
        return self::deserialize($results, RefCapabilitiesResponseCapabilityV2::class);
    }

    /**
     * Make the set from the raw `items` of a contract-definitions response.
     *
     * Contract definitions carry no country and no requires or excludes, so do not read this set as
     * a shipment answer.
     */
    public static function fromContractDefinitionItems(array $items): self
    {
        return self::deserialize($items, RefCapabilitiesContractDefinitionsResponseContractDefinitionsV2::class);
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

        foreach ($this->results as $result) {
            $carrier = self::carrierOf($result);

            if (null !== $carrier && ! in_array($carrier, $carriers, true)) {
                $carriers[] = $carrier;
            }
        }

        return $carriers;
    }

    /** @return string[] */
    public function packageTypesFor(string $carrier): array
    {
        return $this->union($carrier, null, static function (ModelInterface $result): array {
            return self::packageTypesOf($result);
        });
    }

    /** @return string[] */
    public function deliveryTypesFor(string $carrier, ?string $packageType = null): array
    {
        return $this->union($carrier, $packageType, static function (ModelInterface $result): array {
            return self::translate($result->getDeliveryTypes(), [DeliveryType::class, 'fromV2Name']);
        });
    }

    /**
     * Get every option offered that the SDK and the module know, in the order of ShipmentOption::DEFINITIONS.
     *
     * @return string[] module option names
     */
    public function optionsFor(string $carrier, ?string $packageType = null): array
    {
        return ShipmentOption::ordered($this->union($carrier, $packageType, static function (ModelInterface $result): array {
            $options = $result->getOptions();
            $names   = [];

            foreach (null === $options ? [] : $options::getters() as $property => $getter) {
                $name = null === $options->$getter() ? null : ShipmentOption::fromV2Name($options::attributeMap()[$property]);

                if (null !== $name) {
                    $names[] = $name;
                }
            }

            return $names;
        }));
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
        return $this->permissive || null !== $this->optionValue($carrier, $packageType, $option);
    }

    /** Get the option's generated model from the first matching result that carries it. */
    public function optionValue(string $carrier, ?string $packageType, string $option): ?ModelInterface
    {
        foreach ($this->matching($carrier, $packageType) as $result) {
            $value = self::optionOf($result, $option);

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

        foreach ($this->matching($carrier, $packageType) as $result) {
            $collo    = $result->getCollo();
            $colloMax = null === $collo ? null : $collo->getMax();

            if (null !== $colloMax) {
                $max = null === $max ? $colloMax : max($max, $colloMax);
            }
        }

        return $max;
    }

    /**
     * Get every v2 value the SDK does not know, deduplicated by kind: a sign that the SDK needs an update.
     *
     * @return array<string,string[]>
     */
    public function unknownValues(): array
    {
        $unknown = ['carrier' => [], 'packageType' => [], 'deliveryType' => []];

        foreach ($this->results as $result) {
            $unknown['carrier'][]      = self::notIn([$result->getCarrier()], RefCapabilitiesSharedCarrierV2::getAllowableEnumValues());
            $unknown['packageType'][]  = self::notIn($result->getPackageTypes(), RefShipmentPackageTypeV2::getAllowableEnumValues());
            $unknown['deliveryType'][] = self::notIn($result->getDeliveryTypes(), RefTypesDeliveryTypeV2::getAllowableEnumValues());
        }

        return array_map([self::class, 'flattenUnique'], $unknown);
    }

    /** @param class-string<ModelInterface> $class */
    private static function deserialize(array $entries, string $class): self
    {
        $results = [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                self::logSkipped($index, sprintf('a %s, not an object', gettype($entry)));
                continue;
            }

            try {
                $results[] = ObjectSerializer::deserialize($entry, $class);
            } catch (Throwable $e) {
                self::logSkipped($index, $e->getMessage());
            }
        }

        return new self($results, false);
    }

    /** Logged, so a changed API answer does not look like a lost carrier. */
    private static function logSkipped($index, string $reason): void
    {
        Logger::notice(sprintf('MyParcel capabilities: entry %s skipped: %s', (string) $index, $reason));
    }

    private static function optionOf(ModelInterface $result, string $option): ?ModelInterface
    {
        $options  = $result->getOptions();
        $property = null === $options
            ? false
            : array_search(ShipmentOption::toV2Name($option), $options::attributeMap(), true);

        if (false === $property) {
            return null;
        }

        $getter = $options::getters()[$property];

        return $options->$getter();
    }

    private static function carrierOf(ModelInterface $result): ?string
    {
        $v2Carrier = (string) $result->getCarrier();

        return '' === $v2Carrier ? null : Carrier::fromV2Name($v2Carrier);
    }

    /** @return string[] */
    private static function packageTypesOf(ModelInterface $result): array
    {
        return self::translate($result->getPackageTypes(), [PackageType::class, 'fromV2Name']);
    }

    /**
     * @param  callable(string):?string $resolve
     * @return string[] the module names; a value the module cannot translate is left out
     */
    private static function translate(?array $values, callable $resolve): array
    {
        $names = [];

        foreach ($values ?? [] as $value) {
            $name = is_string($value) ? $resolve($value) : null;

            if (null !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return string[] */
    private static function notIn(?array $values, array $allowed): array
    {
        return array_values(array_filter($values ?? [], static function ($value) use ($allowed): bool {
            return is_string($value) && '' !== $value && ! in_array($value, $allowed, true);
        }));
    }

    /**
     * @param  callable(ModelInterface):string[] $extract
     * @return string[]
     */
    private function union(string $carrier, ?string $packageType, callable $extract): array
    {
        return self::flattenUnique(array_map($extract, $this->matching($carrier, $packageType)));
    }

    /**
     * @param  string[][] $lists
     * @return string[] every value once, in first-seen order
     */
    private static function flattenUnique(array $lists): array
    {
        return array_values(array_unique(array_merge([], ...$lists)));
    }

    /** @return ModelInterface[] */
    private function matching(string $carrier, ?string $packageType): array
    {
        $matching = [];

        foreach ($this->results as $result) {
            if ($carrier !== self::carrierOf($result)) {
                continue;
            }

            if (null !== $packageType && ! in_array($packageType, self::packageTypesOf($result), true)) {
                continue;
            }

            $matching[] = $result;
        }

        return $matching;
    }
}
