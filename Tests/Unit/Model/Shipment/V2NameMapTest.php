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

// The request model has no setter for these two, so the SDK cannot ask about them. They still
// appear in a response, so the module keeps reading them; Client logs a request that sends one.
const OPTIONS_THE_REQUEST_CANNOT_CARRY = [ShipmentOption::FRESH_FOOD, ShipmentOption::FROZEN];

it('agrees with the SDK request mapper on every option wire key', function () {
    foreach (ShipmentOption::V2_KEYS_MAP as $moduleName => $v2Key) {
        if (in_array($moduleName, OPTIONS_THE_REQUEST_CANNOT_CARRY, true)) {
            continue;
        }

        expect(mappedOptionKeys($moduleName))
            ->toBe([$v2Key], "option '$moduleName' should map to '$v2Key'");
    }
});

it('names the two options the request model cannot carry, so the gap stays deliberate', function () {
    foreach (OPTIONS_THE_REQUEST_CANNOT_CARRY as $moduleName) {
        expect(mappedOptionKeys($moduleName))->toBe([], "'$moduleName' unexpectedly became sendable")
            ->and(ShipmentOption::toV2Key($moduleName))->not->toBeNull();
    }
});

it('covers every shipment option the module defines', function () {
    $constants = (new ReflectionClass(ShipmentOption::class))->getConstants();

    foreach ($constants as $name => $value) {
        if (! is_string($value) || 0 === strpos($name, 'EXTRA_') || 'V2_KEYS_MAP' === $name) {
            continue;
        }

        expect(ShipmentOption::V2_KEYS_MAP)->toHaveKey($value);
    }
});

it('maps every package type to a v2 enum value the SDK allows', function () {
    $allowed = RefShipmentPackageTypeV2::getAllowableEnumValues();

    expect(PackageType::V2_NAMES_MAP)->toHaveCount(7);

    foreach (PackageType::V2_NAMES_MAP as $name => $v2Name) {
        expect($allowed)->toContain($v2Name)
            ->and(PackageType::fromV2Name($v2Name))->toBe($name);
    }
});

it('maps every delivery type to a v2 enum value the SDK allows', function () {
    $allowed = RefTypesDeliveryTypeV2::getAllowableEnumValues();

    expect(DeliveryType::V2_NAMES_MAP)->toHaveCount(7);

    foreach (DeliveryType::V2_NAMES_MAP as $name => $v2Name) {
        expect($allowed)->toContain($v2Name)
            ->and(DeliveryType::fromV2Name($v2Name))->toBe($name);
    }
});

it('maps every carrier to a v2 enum value the SDK allows', function () {
    $allowed = RefCapabilitiesSharedCarrierV2::getAllowableEnumValues();

    foreach (Carrier::V2_NAMES_MAP as $name => $v2Name) {
        expect($allowed)->toContain($v2Name)
            ->and(Carrier::fromV2Name($v2Name))->toBe($name);
    }
});

it('names every carrier the module has settings for', function () {
    expect(array_keys(Carrier::V2_NAMES_MAP))
        ->toBe(array_keys(\MyParcelNL\Magento\Service\Config::CARRIERS_XML_PATH_MAP))
        ->and(array_keys(Carrier::HUMAN_MAP))
        ->toBe(array_keys(Carrier::V2_NAMES_MAP));
});

it('answers null for a value it does not know rather than inventing one', function () {
    expect(PackageType::fromV2Name('HOVERCRAFT'))->toBeNull()
        ->and(DeliveryType::fromV2Name('TELEPORT_DELIVERY'))->toBeNull()
        ->and(Carrier::fromV2Name('FUTURE_CARRIER'))->toBeNull()
        ->and(ShipmentOption::fromV2Key('aBrandNewOption'))->toBeNull()
        ->and(Carrier::toV2Name('nope'))->toBeNull();
});
