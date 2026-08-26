<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentPackageType;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesDeliveryType;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

/**
 * Compares every module constant against the beta.15 SDK constant it replaces. Deleted at Phase 9
 * with the pin bump; it is the one file allowed to reference AbstractConsignment.
 */
it('carries the beta.15 package type ids and names', function () {
    expect(PackageType::PACKAGE)->toBe(AbstractConsignment::PACKAGE_TYPE_PACKAGE)
        ->and(PackageType::MAILBOX)->toBe(AbstractConsignment::PACKAGE_TYPE_MAILBOX)
        ->and(PackageType::LETTER)->toBe(AbstractConsignment::PACKAGE_TYPE_LETTER)
        ->and(PackageType::DIGITAL_STAMP)->toBe(AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP)
        ->and(PackageType::PACKAGE_SMALL)->toBe(AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL)
        ->and(PackageType::PACKAGE_NAME)->toBe(AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME)
        ->and(PackageType::MAILBOX_NAME)->toBe(AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME)
        ->and(PackageType::LETTER_NAME)->toBe(AbstractConsignment::PACKAGE_TYPE_LETTER_NAME)
        ->and(PackageType::DIGITAL_STAMP_NAME)->toBe(AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME)
        ->and(PackageType::PACKAGE_SMALL_NAME)->toBe(AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL_NAME)
        ->and(PackageType::DEFAULT)->toBe(AbstractConsignment::DEFAULT_PACKAGE_TYPE)
        ->and(PackageType::DEFAULT_NAME)->toBe(AbstractConsignment::DEFAULT_PACKAGE_TYPE_NAME);
});

it('keeps every beta.15 package type collection entry unchanged', function () {
    // Derived from the surviving name map: the standalone IDS and NAMES arrays went in 4c.
    expect(array_diff(AbstractConsignment::PACKAGE_TYPES_IDS, array_values(PackageType::NAMES_IDS_MAP)))->toBe([])
        ->and(array_diff(AbstractConsignment::PACKAGE_TYPES_NAMES, array_keys(PackageType::NAMES_IDS_MAP)))->toBe([])
        ->and(array_intersect_key(PackageType::NAMES_IDS_MAP, AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP))
        ->toBe(AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP);
});

it('carries the beta.15 delivery type ids and names', function () {
    expect(DeliveryType::MORNING)->toBe(AbstractConsignment::DELIVERY_TYPE_MORNING)
        ->and(DeliveryType::STANDARD)->toBe(AbstractConsignment::DELIVERY_TYPE_STANDARD)
        ->and(DeliveryType::EVENING)->toBe(AbstractConsignment::DELIVERY_TYPE_EVENING)
        ->and(DeliveryType::PICKUP)->toBe(AbstractConsignment::DELIVERY_TYPE_PICKUP)
        ->and(DeliveryType::EXPRESS)->toBe(AbstractConsignment::DELIVERY_TYPE_EXPRESS)
        ->and(DeliveryType::MORNING_NAME)->toBe(AbstractConsignment::DELIVERY_TYPE_MORNING_NAME)
        ->and(DeliveryType::STANDARD_NAME)->toBe(AbstractConsignment::DELIVERY_TYPE_STANDARD_NAME)
        ->and(DeliveryType::EVENING_NAME)->toBe(AbstractConsignment::DELIVERY_TYPE_EVENING_NAME)
        ->and(DeliveryType::PICKUP_NAME)->toBe(AbstractConsignment::DELIVERY_TYPE_PICKUP_NAME)
        ->and(DeliveryType::EXPRESS_NAME)->toBe(AbstractConsignment::DELIVERY_TYPE_EXPRESS_NAME)
        ->and(DeliveryType::DEFAULT)->toBe(AbstractConsignment::DEFAULT_DELIVERY_TYPE)
        ->and(DeliveryType::DEFAULT_NAME)->toBe(AbstractConsignment::DEFAULT_DELIVERY_TYPE_NAME);
});

it('keeps every beta.15 delivery type collection entry unchanged', function () {
    expect(array_diff(AbstractConsignment::DELIVERY_TYPES_IDS, array_values(DeliveryType::NAMES_IDS_MAP)))->toBe([])
        ->and(array_diff(AbstractConsignment::DELIVERY_TYPES_NAMES, array_keys(DeliveryType::NAMES_IDS_MAP)))->toBe([])
        ->and(array_intersect_key(DeliveryType::NAMES_IDS_MAP, AbstractConsignment::DELIVERY_TYPES_NAMES_IDS_MAP))
        ->toBe(AbstractConsignment::DELIVERY_TYPES_NAMES_IDS_MAP);
});

it('sources the four types beta.15 never had from the generated refs', function () {
    expect(PackageType::PALLET)->toBe(RefShipmentPackageType::PALLET)
        ->and(PackageType::ENVELOPE)->toBe(RefShipmentPackageType::ENVELOPE)
        ->and(DeliveryType::SAME_DAY)->toBe(RefTypesDeliveryType::SAME_DAY)
        ->and(DeliveryType::EARLY_MORNING)->toBe(RefTypesDeliveryType::EARLY_MORNING);
});

it('keeps the same-day delivery type distinct from the same-day shipment option', function () {
    expect(DeliveryType::SAME_DAY_NAME)->toBe('same_day')
        ->and(ShipmentOption::SAME_DAY_DELIVERY)->toBe('same_day_delivery')
        ->and(DeliveryType::SAME_DAY_NAME)->not->toBe(ShipmentOption::SAME_DAY_DELIVERY);
});

it('carries the beta.15 shipment option keys', function () {
    expect(ShipmentOption::AGE_CHECK)->toBe(AbstractConsignment::SHIPMENT_OPTION_AGE_CHECK)
        ->and(ShipmentOption::HIDE_SENDER)->toBe(AbstractConsignment::SHIPMENT_OPTION_HIDE_SENDER)
        ->and(ShipmentOption::INSURANCE)->toBe(AbstractConsignment::SHIPMENT_OPTION_INSURANCE)
        ->and(ShipmentOption::LARGE_FORMAT)->toBe(AbstractConsignment::SHIPMENT_OPTION_LARGE_FORMAT)
        ->and(ShipmentOption::ONLY_RECIPIENT)->toBe(AbstractConsignment::SHIPMENT_OPTION_ONLY_RECIPIENT)
        ->and(ShipmentOption::PRINTERLESS_RETURN)->toBe(AbstractConsignment::SHIPMENT_OPTION_PRINTERLESS_RETURN)
        ->and(ShipmentOption::RETURN)->toBe(AbstractConsignment::SHIPMENT_OPTION_RETURN)
        ->and(ShipmentOption::SAME_DAY_DELIVERY)->toBe(AbstractConsignment::SHIPMENT_OPTION_SAME_DAY_DELIVERY)
        ->and(ShipmentOption::SIGNATURE)->toBe(AbstractConsignment::SHIPMENT_OPTION_SIGNATURE)
        ->and(ShipmentOption::COLLECT)->toBe(AbstractConsignment::SHIPMENT_OPTION_COLLECT)
        ->and(ShipmentOption::RECEIPT_CODE)->toBe(AbstractConsignment::SHIPMENT_OPTION_RECEIPT_CODE)
        ->and(ShipmentOption::PRIORITY_DELIVERY)->toBe(AbstractConsignment::SHIPMENT_OPTION_PRIORITY_DELIVERY)
        ->and(ShipmentOption::FRESH_FOOD)->toBe(AbstractConsignment::SHIPMENT_OPTION_FRESH_FOOD)
        ->and(ShipmentOption::FROZEN)->toBe(AbstractConsignment::SHIPMENT_OPTION_FROZEN)
        ->and(ShipmentOption::TO_CHECK)->toBe(AbstractConsignment::SHIPMENT_OPTIONS_TO_CHECK);
});

it('carries the beta.15 extra option keys', function () {
    expect(ShipmentOption::EXTRA_DELIVERY_DATE)->toBe(AbstractConsignment::EXTRA_OPTION_DELIVERY_DATE)
        ->and(ShipmentOption::EXTRA_DELIVERY_MONDAY)->toBe(AbstractConsignment::EXTRA_OPTION_DELIVERY_MONDAY)
        ->and(ShipmentOption::EXTRA_DELIVERY_SATURDAY)->toBe(AbstractConsignment::EXTRA_OPTION_DELIVERY_SATURDAY)
        ->and(ShipmentOption::EXTRA_MULTI_COLLO)->toBe(AbstractConsignment::EXTRA_OPTION_MULTI_COLLO);
});

it('carries the beta.15 country codes', function () {
    expect(CountryCode::CC_NL)->toBe(AbstractConsignment::CC_NL)
        ->and(CountryCode::CC_BE)->toBe(AbstractConsignment::CC_BE);
});

it('swaps Kosovo for Malta in the EU list, on purpose', function () {
    $wasEu = AbstractConsignment::EURO_COUNTRIES;
    $isEu  = CountryCode::EU_COUNTRIES;

    expect(array_values(array_diff($isEu, $wasEu)))->toBe(['MT'])
        ->and(array_values(array_diff($wasEu, $isEu)))->toBe(['XK']);
});

it('answers the EU and ROW zone question', function () {
    expect(CountryCode::isEu('NL'))->toBeTrue()
        ->and(CountryCode::isEu('MT'))->toBeTrue()
        ->and(CountryCode::isEu('XK'))->toBeFalse()
        ->and(CountryCode::isEu('US'))->toBeFalse()
        ->and(CountryCode::isRow('US'))->toBeTrue()
        ->and(CountryCode::isRow('NL'))->toBeFalse();
});

it('treats a missing country as outside the EU', function () {
    expect(CountryCode::isEu(null))->toBeFalse()
        ->and(CountryCode::isRow(null))->toBeTrue()
        ->and(CountryCode::isEu(''))->toBeFalse();
});
