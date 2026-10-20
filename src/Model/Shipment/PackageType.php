<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentPackageType;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentPackageTypeV2;

/**
 * Package type names and ids.
 *
 * The names are ours and are stored in config and on orders; the ids are the SDK's. The two
 * vocabularies differ ('letter' is UNFRANKED, 'package_small' is SMALL_PACKAGE), so always hand
 * the SDK an id, never one of these names. See docs/sdk-v11.md.
 */
final class PackageType
{
    use MapsNamesToIds;
    use MapsV2Names;

    /** Names the type in an exception message. */
    public const TYPE_LABEL = 'package type';

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
}
