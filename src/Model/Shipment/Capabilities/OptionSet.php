<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * The `options` object of one capabilities result.
 *
 * Holds every key the response carried, translated on read rather than on parse: a key the module
 * has no option for is kept and reported through unknownKeys(), never dropped. Presence of a key
 * means the option is available; its value carries isRequired, isSelectedByDefault and, for
 * insurance, min/max/default.
 */
final class OptionSet
{
    /** @var array<string,mixed> keyed by the response's own camelCase key */
    private array $raw;

    /** @var string[] response keys with no module option */
    private array $unknownKeys;

    private function __construct(array $raw, array $unknownKeys)
    {
        $this->raw         = $raw;
        $this->unknownKeys = $unknownKeys;
    }

    public static function fromArray(array $options): self
    {
        $unknown = [];

        foreach (array_keys($options) as $key) {
            if (null === ShipmentOption::fromV2Key((string) $key)) {
                $unknown[] = (string) $key;
            }
        }

        return new self($options, $unknown);
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function has(string $moduleOptionName): bool
    {
        $key = ShipmentOption::toV2Key($moduleOptionName);

        return null !== $key && array_key_exists($key, $this->raw);
    }

    /**
     * The option's own properties, verbatim. Phase 5 reads insurance min/max through this.
     *
     * @return array<string,mixed>|null
     */
    public function valueFor(string $moduleOptionName): ?array
    {
        $key = ShipmentOption::toV2Key($moduleOptionName);

        if (null === $key || ! array_key_exists($key, $this->raw)) {
            return null;
        }

        return is_array($this->raw[$key]) ? $this->raw[$key] : [];
    }

    /**
     * Module names for every option the response listed and we recognise.
     *
     * @return string[]
     */
    public function moduleNames(): array
    {
        $names = [];

        foreach (array_keys($this->raw) as $key) {
            $name = ShipmentOption::fromV2Key((string) $key);

            if (null !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return string[] */
    public function unknownKeys(): array
    {
        return $this->unknownKeys;
    }
}
