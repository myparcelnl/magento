<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use InvalidArgumentException;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesDeliveryType;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesDeliveryTypeV2;

/**
 * Delivery type names and ids.
 *
 * Same split as PackageType: names ours, ids the SDK's, so always hand the SDK an id.
 *
 * SAME_DAY is the delivery type 'same_day'. ShipmentOption::SAME_DAY_DELIVERY is the string
 * 'same_day_delivery' and a different thing entirely.
 */
final class DeliveryType
{
    public const MORNING       = RefTypesDeliveryType::MORNING;
    public const STANDARD      = RefTypesDeliveryType::STANDARD;
    public const EVENING       = RefTypesDeliveryType::EVENING;
    public const PICKUP        = RefTypesDeliveryType::PICKUP;
    public const SAME_DAY      = RefTypesDeliveryType::SAME_DAY;
    public const EXPRESS       = RefTypesDeliveryType::EXPRESS;
    public const EARLY_MORNING = RefTypesDeliveryType::EARLY_MORNING;

    // Every name is the v2 enum minus its _DELIVERY suffix, lower-cased; DeliveryTypeTransformer
    // puts the suffix back, so a new type needs no entry there.
    public const MORNING_NAME       = 'morning';
    public const STANDARD_NAME      = 'standard';
    public const EVENING_NAME       = 'evening';
    public const PICKUP_NAME        = 'pickup';
    public const SAME_DAY_NAME      = 'same_day';
    public const EXPRESS_NAME       = 'express';
    public const EARLY_MORNING_NAME = 'early_morning';

    public const DEFAULT      = self::STANDARD;
    public const DEFAULT_NAME = self::STANDARD_NAME;

    public const IDS
        = [
            self::MORNING,
            self::STANDARD,
            self::EVENING,
            self::PICKUP,
            self::SAME_DAY,
            self::EXPRESS,
            self::EARLY_MORNING,
        ];

    public const NAMES
        = [
            self::MORNING_NAME,
            self::STANDARD_NAME,
            self::EVENING_NAME,
            self::PICKUP_NAME,
            self::SAME_DAY_NAME,
            self::EXPRESS_NAME,
            self::EARLY_MORNING_NAME,
        ];

    public const NAMES_IDS_MAP
        = [
            self::MORNING_NAME       => self::MORNING,
            self::STANDARD_NAME      => self::STANDARD,
            self::EVENING_NAME       => self::EVENING,
            self::PICKUP_NAME        => self::PICKUP,
            self::SAME_DAY_NAME      => self::SAME_DAY,
            self::EXPRESS_NAME       => self::EXPRESS,
            self::EARLY_MORNING_NAME => self::EARLY_MORNING,
        ];

    /**
     * Module name to the Core API v2 name a capabilities response speaks.
     */
    public const V2_NAMES_MAP
        = [
            self::MORNING_NAME       => RefTypesDeliveryTypeV2::MORNING,
            self::STANDARD_NAME      => RefTypesDeliveryTypeV2::STANDARD,
            self::EVENING_NAME       => RefTypesDeliveryTypeV2::EVENING,
            self::PICKUP_NAME        => RefTypesDeliveryTypeV2::PICKUP,
            self::SAME_DAY_NAME      => RefTypesDeliveryTypeV2::SAME_DAY,
            self::EXPRESS_NAME       => RefTypesDeliveryTypeV2::EXPRESS,
            self::EARLY_MORNING_NAME => RefTypesDeliveryTypeV2::EARLY_MORNING,
        ];

    public static function toV2Name(string $name): ?string
    {
        return self::V2_NAMES_MAP[$name] ?? null;
    }

    /** Null for a v2 name the module does not know; the caller logs it rather than inventing one. */
    public static function fromV2Name(string $v2Name): ?string
    {
        $name = array_search($v2Name, self::V2_NAMES_MAP, true);

        return false === $name ? null : $name;
    }

    /**
     * Strict: use on any path that ends in an API request.
     *
     * @throws \InvalidArgumentException
     */
    public static function toId(string $name): int
    {
        if (! isset(self::NAMES_IDS_MAP[$name])) {
            throw new InvalidArgumentException("Unknown delivery type '$name'");
        }

        return self::NAMES_IDS_MAP[$name];
    }

    /** Forgiving: use on read paths. */
    public static function toIdOrNull(?string $name): ?int
    {
        return null === $name ? null : (self::NAMES_IDS_MAP[$name] ?? null);
    }

    /** @throws \InvalidArgumentException */
    public static function nameFromId(int $id): string
    {
        $name = self::nameFromIdOrNull($id);

        if (null === $name) {
            throw new InvalidArgumentException("Unknown delivery type id '$id'");
        }

        return $name;
    }

    public static function nameFromIdOrNull(?int $id): ?string
    {
        if (null === $id) {
            return null;
        }

        $name = array_search($id, self::NAMES_IDS_MAP, true);

        return false === $name ? null : $name;
    }

    public static function isValidName(string $name): bool
    {
        return isset(self::NAMES_IDS_MAP[$name]);
    }

    public static function isValidId(int $id): bool
    {
        return in_array($id, self::IDS, true);
    }
}
