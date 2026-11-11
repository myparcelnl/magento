<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefCapabilitiesSharedCarrierV2;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentPackageTypeV2;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesDeliveryTypeV2;
use MyParcelNL\Sdk\Client\Generated\CoreApi\ObjectSerializer;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesMapper;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

/**
 * The module maps its own names to the Core API v2 vocabulary on both sides of a capabilities call.
 * These assertions pin the two sides together: the request side is the SDK's mapper, the response
 * side is ours, and a drift between them is a silent wrong answer rather than an error.
 *
 * The option keys are asserted by round-tripping a real request rather than by comparing to a copy
 * of CapabilitiesMapper's own map, which is private.
 */

/** Wire keys the request model carries for one module option, via the SDK's own mapper. */
function mappedOptionKeys(string $moduleOption): array
{
    $core = (new CapabilitiesMapper())->mapToCoreApi(
        CapabilitiesRequest::forCountry('NL')->withOptions([$moduleOption => true])
    );

    return array_keys((array) ObjectSerializer::sanitizeForSerialization($core->getOptions()));
}

/** @return string[] every option name the module defines as a constant */
function moduleOptionNames(): array
{
    return array_values(array_filter(
        (new ReflectionClass(ShipmentOption::class))->getConstants(),
        'is_string'
    ));
}

it('agrees with the SDK request mapper on every option wire key', function () {
    foreach (moduleOptionNames() as $moduleName) {
        $v2Key = ShipmentOption::toV2Name($moduleName);

        expect(mappedOptionKeys($moduleName))
            ->toBe([$v2Key], "option '$moduleName' should map to '$v2Key'");
    }
});

it('carries fresh food and frozen, which the request model could not always ask about', function () {
    // Both were read-only at beta.15: they appear in a capabilities response but CapabilitiesOptionsV2
    // had no setter, so a request could not ask about them. beta.31 added setFreshFood() and
    // setFrozen(), closing that gap. Asserted head-on so a regression upstream is a
    // failure here rather than a silently unasked question.
    foreach ([ShipmentOption::FRESH_FOOD, ShipmentOption::FROZEN] as $moduleName) {
        expect(mappedOptionKeys($moduleName))
            ->toBe([ShipmentOption::toV2Name($moduleName)], "'$moduleName' stopped being sendable");
    }
});

it('round-trips every stored option name through its wire key', function () {
    expect(moduleOptionNames())->toHaveCount(14);

    foreach (moduleOptionNames() as $moduleName) {
        expect(ShipmentOption::fromV2Name(ShipmentOption::toV2Name($moduleName)))->toBe($moduleName);
    }
});

it('takes the aliases from the SDK', function () {
    expect(ShipmentOption::toV2Name(ShipmentOption::RETURN))->toBe('returnOnFirstFailedDelivery')
        ->and(ShipmentOption::toV2Name(ShipmentOption::PRINTERLESS_RETURN))->toBe('printReturnLabelAtDropOff')
        ->and(ShipmentOption::fromV2Name('requiresSignature'))->toBe(ShipmentOption::SIGNATURE);
});

it('derives an option name in both directions for a wire key it has never seen', function () {
    expect(ShipmentOption::fromV2Name('noTracking'))->toBe('no_tracking')
        ->and(ShipmentOption::toV2Name('no_tracking'))->toBe('noTracking')
        ->and(ShipmentOption::fromV2Name('requiresFoo'))->toBe('requires_foo')
        ->and(ShipmentOption::toV2Name('requires_foo'))->toBe('requiresFoo');
});

it('maps every package type to a v2 enum value the SDK allows, and back', function () {
    foreach (PackageType::V2_NAMES_MAP as $name => $v2Name) {
        expect(RefShipmentPackageTypeV2::getAllowableEnumValues())->toContain($v2Name)
            ->and(PackageType::fromV2Name($v2Name))->toBe($name);
    }
});

// An SDK update that adds a value fails here, not as a package type the checkout drops without a log.
it('has a module name for every package type the SDK allows', function () {
    expect(array_values(array_diff(RefShipmentPackageTypeV2::getAllowableEnumValues(), PackageType::V2_NAMES_MAP)))->toBe([]);
});

it('maps every delivery type to a v2 enum value the SDK allows, and back', function () {
    foreach (DeliveryType::V2_NAMES_MAP as $name => $v2Name) {
        expect(RefTypesDeliveryTypeV2::getAllowableEnumValues())->toContain($v2Name)
            ->and(DeliveryType::fromV2Name($v2Name))->toBe($name);
    }
});

// An SDK update that adds a value fails here, not as a delivery type the checkout drops without a log.
it('has a module name for every delivery type the SDK allows', function () {
    expect(array_values(array_diff(RefTypesDeliveryTypeV2::getAllowableEnumValues(), DeliveryType::V2_NAMES_MAP)))->toBe([]);
});

it('round-trips every carrier the SDK knows through its module name', function () {
    $allowed = RefCapabilitiesSharedCarrierV2::getAllowableEnumValues();

    foreach ($allowed as $v2Name) {
        expect(Carrier::toV2Name(Carrier::fromV2Name($v2Name)))->toBe($v2Name);
    }
});

it('keeps the module name every legacy carrier stores its settings under', function () {
    expect(array_map([Carrier::class, 'toV2Name'], legacyCarriers()))->toBe([
        'POSTNL', 'DHL_FOR_YOU', 'DHL_EUROPLUS', 'DHL_PARCEL_CONNECT', 'UPS_STANDARD', 'DPD', 'GLS', 'TRUNKRS',
    ]);
});

it('resolves the id of a carrier the module has no code for', function () {
    expect(Carrier::idFor('upsexpresssaver'))->toBe(13)
        ->and(Carrier::idFor('cheapcargo'))->toBe(3)
        ->and(Carrier::idFor('postnl'))->toBe(1);
});

it('derives a carrier name from any v2 name, and knows only the SDK\'s', function () {
    expect(Carrier::fromV2Name('HOOPLA'))->toBe('hoopla')
        ->and(Carrier::fromV2Name('UPS_EXPRESS_SAVER'))->toBe('upsexpresssaver')
        ->and(Carrier::toV2Name('hoopla'))->toBeNull()
        ->and(Carrier::idFor('hoopla'))->toBeNull();
});

/**
 * The module's roster is a subset of the SDK's, never a parallel list. An SDK rename or removal
 * must fail here rather than silently drop a carrier from the admin.
 */
it('takes every carrier name from the sdk registry', function () {
    $sdkNames = array_map(
        static fn(string $class): string => $class::NAME,
        \MyParcelNL\Sdk\Model\Carrier\CarrierFactory::CARRIER_CLASSES
    );

    expect(array_diff(legacyCarriers(), $sdkNames))->toBe([])
        ->and(Carrier::humanFor('dhlforyou'))->toBe('DHL For You')
        ->and(Carrier::humanFor('nonexistent'))->toBe('nonexistent');
});

it('answers null for a package or delivery type it does not know rather than inventing one', function () {
    expect(PackageType::fromV2Name('HOVERCRAFT'))->toBeNull()
        ->and(DeliveryType::fromV2Name('TELEPORT_DELIVERY'))->toBeNull();
});

it('prices every SDK delivery type per type, minus the named exceptions', function () {
    // the full list except the exceptions, so a type the SDK adds needs no entry here
    $exceptions = [DeliveryType::SAME_DAY_NAME, DeliveryType::EXPRESS_NAME, DeliveryType::EARLY_MORNING_NAME];
    $known      = array_filter(array_map([DeliveryType::class, 'fromV2Name'], RefTypesDeliveryTypeV2::getAllowableEnumValues()));

    expect(DeliveryType::pricedPerTypeNames())->toBe(array_values(array_diff($known, $exceptions)))
        ->and(array_intersect(DeliveryType::pricedPerTypeNames(), $exceptions))->toBe([]);
});
