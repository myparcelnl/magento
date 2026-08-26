<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use InvalidArgumentException;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentPackageType;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentPackageTypeV2;

/**
 * Package type names and ids.
 *
 * The names are ours and are stored in config and on orders; the ids are the SDK's. The two
 * vocabularies differ ('letter' is UNFRANKED, 'package_small' is SMALL_PACKAGE), so always hand
 * the SDK an id, never one of these names. See TR-000005.
 */
final class PackageType
{
    public const PACKAGE       = RefShipmentPackageType::PACKAGE;
    public const MAILBOX       = RefShipmentPackageType::MAILBOX;
    public const LETTER        = RefShipmentPackageType::UNFRANKED;
    public const DIGITAL_STAMP = RefShipmentPackageType::DIGITAL_STAMP;
    public const PALLET        = RefShipmentPackageType::PALLET;
    public const PACKAGE_SMALL = RefShipmentPackageType::SMALL_PACKAGE;
    public const ENVELOPE      = RefShipmentPackageType::ENVELOPE;

    // 'letter' and 'package_small' are irregular for historical reasons; every other name is the
    // v2 enum lower-cased. Do not add a third irregular one.
    public const PACKAGE_NAME       = 'package';
    public const MAILBOX_NAME       = 'mailbox';
    public const LETTER_NAME        = 'letter';
    public const DIGITAL_STAMP_NAME = 'digital_stamp';
    public const PALLET_NAME        = 'pallet';
    public const PACKAGE_SMALL_NAME = 'package_small';
    public const ENVELOPE_NAME      = 'envelope';

    public const DEFAULT      = self::PACKAGE;
    public const DEFAULT_NAME = self::PACKAGE_NAME;

    public const IDS
        = [
            self::PACKAGE,
            self::MAILBOX,
            self::LETTER,
            self::DIGITAL_STAMP,
            self::PALLET,
            self::PACKAGE_SMALL,
            self::ENVELOPE,
        ];

    public const NAMES
        = [
            self::PACKAGE_NAME,
            self::MAILBOX_NAME,
            self::LETTER_NAME,
            self::DIGITAL_STAMP_NAME,
            self::PALLET_NAME,
            self::PACKAGE_SMALL_NAME,
            self::ENVELOPE_NAME,
        ];

    public const NAMES_IDS_MAP
        = [
            self::PACKAGE_NAME       => self::PACKAGE,
            self::MAILBOX_NAME       => self::MAILBOX,
            self::LETTER_NAME        => self::LETTER,
            self::DIGITAL_STAMP_NAME => self::DIGITAL_STAMP,
            self::PALLET_NAME        => self::PALLET,
            self::PACKAGE_SMALL_NAME => self::PACKAGE_SMALL,
            self::ENVELOPE_NAME      => self::ENVELOPE,
        ];

    /**
     * Module name to the Core API v2 name a capabilities response speaks.
     */
    public const V2_NAMES_MAP
        = [
            self::PACKAGE_NAME       => RefShipmentPackageTypeV2::PACKAGE,
            self::MAILBOX_NAME       => RefShipmentPackageTypeV2::MAILBOX,
            self::LETTER_NAME        => RefShipmentPackageTypeV2::UNFRANKED,
            self::DIGITAL_STAMP_NAME => RefShipmentPackageTypeV2::DIGITAL_STAMP,
            self::PALLET_NAME        => RefShipmentPackageTypeV2::PALLET,
            self::PACKAGE_SMALL_NAME => RefShipmentPackageTypeV2::SMALL_PACKAGE,
            self::ENVELOPE_NAME      => RefShipmentPackageTypeV2::ENVELOPE,
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
            throw new InvalidArgumentException("Unknown package type '$name'");
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
            throw new InvalidArgumentException("Unknown package type id '$id'");
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
