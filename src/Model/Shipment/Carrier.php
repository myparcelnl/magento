<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use MyParcelNL\Sdk\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\Services\Mapping\ApiMapperService;
use Throwable;

/**
 * Carrier names and labels.
 *
 * The lowercase names are ours and are config path segments. Each one derives from the carrier's
 * Core API v2 name, and the SDK's carrier table answers the way back, so no carrier is listed here.
 */
final class Carrier
{
    /** @var array<string, string>|null module name => v2 name, for every carrier the SDK knows */
    private static ?array $v2Names = null;

    /**
     * The module name for any v2 carrier name. One-way on its own, `upsstandard` could have been
     * `UPS_STANDARD` or `UPSSTANDARD`, which is why toV2Name() reads the SDK's table.
     */
    public static function fromV2Name(string $v2Name): string
    {
        return strtolower(str_replace('_', '', $v2Name));
    }

    /** Null for a carrier the SDK does not know. */
    public static function toV2Name(string $name): ?string
    {
        if (null === self::$v2Names) {
            self::$v2Names = [];

            foreach (ApiMapperService::forCarrier()->allRows() as $row) {
                $v2Name = $row[ApiMapperService::COLUMN_V2_NAME] ?? null;

                if (is_string($v2Name)) {
                    self::$v2Names[self::fromV2Name($v2Name)] = $v2Name;
                }
            }
        }

        return self::$v2Names[$name] ?? null;
    }

    public static function knowsV2Name(string $v2Name): bool
    {
        return $v2Name === self::toV2Name(self::fromV2Name($v2Name));
    }

    /** The carrier's v1 id, which a shipment carries. Null for a carrier the SDK does not know. */
    public static function idFor(string $name): ?int
    {
        $v2Name = self::toV2Name($name);

        return null === $v2Name ? null : ApiMapperService::forCarrier()->idFromV2Name($v2Name);
    }

    public static function isExportable(string $name): bool
    {
        return null !== self::idFor($name);
    }

    /** The carrier's label, from the SDK. Its own name back for one the SDK does not know. */
    public static function humanFor(string $name): string
    {
        try {
            return CarrierFactory::createFromName($name)->getHuman();
        } catch (Throwable $e) {
            return $name;
        }
    }
}
