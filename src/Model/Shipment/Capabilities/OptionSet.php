<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use MyParcelNL\Magento\Facade\Logger;
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
            if (! ShipmentOption::knowsV2Name((string) $key)) {
                $unknown[] = (string) $key;
            }
        }

        return new self($options, $unknown);
    }

    public function has(string $moduleOptionName): bool
    {
        return array_key_exists(ShipmentOption::toV2Name($moduleOptionName), $this->raw);
    }

    /**
     * The option's own properties, verbatim. The insurance range reads min/max through this.
     *
     * @return array<string,mixed>|null
     */
    public function valueFor(string $moduleOptionName): ?array
    {
        $key = ShipmentOption::toV2Name($moduleOptionName);

        if (! array_key_exists($key, $this->raw)) {
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
            if (ShipmentOption::knowsV2Name((string) $key)) {
                $names[] = ShipmentOption::fromV2Name((string) $key);
            }
        }

        return $names;
    }

    /**
     * Module names for every option the response listed, including one the module only derives.
     *
     * @return string[]
     */
    public function allNames(): array
    {
        return array_map(static function ($key): string {
            return ShipmentOption::fromV2Name((string) $key);
        }, array_keys($this->raw));
    }

    /**
     * The companions the option needs, in module names. Single level: the caller must not follow a
     * companion's own requires, see docs/design/capability-option-dependencies.md.
     *
     * @return string[]
     */
    public function requiresFor(string $moduleOptionName): array
    {
        return $this->dependencies($moduleOptionName, 'requires');
    }

    /** @return string[] the options this one may not ship with, in module names */
    public function excludesFor(string $moduleOptionName): array
    {
        return $this->dependencies($moduleOptionName, 'excludes');
    }

    /** @return string[] */
    public function unknownKeys(): array
    {
        return $this->unknownKeys;
    }

    /** @return string[] */
    private function dependencies(string $moduleOptionName, string $kind): array
    {
        $listed = ($this->valueFor($moduleOptionName) ?? [])[$kind] ?? [];
        $names  = [];

        foreach (is_array($listed) ? $listed : [$listed] as $v2Name) {
            if (! is_string($v2Name) || '' === $v2Name) {
                Logger::notice(sprintf(
                    'MyParcel capabilities: %s of %s holds an entry that is not an option name — skipped',
                    $kind,
                    ShipmentOption::toV2Name($moduleOptionName)
                ));

                continue;
            }

            $names[] = ShipmentOption::fromV2Name($v2Name);
        }

        return $names;
    }
}
