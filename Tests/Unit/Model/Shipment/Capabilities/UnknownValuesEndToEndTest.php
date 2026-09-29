<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

// A new carrier, package type, delivery type and option, through the real Client and cache.

/** @return array[] */
function unknownValuesResults(): array
{
    return [
        capabilityResult([
            'packageTypes'  => ['PACKAGE', 'HOVERCRATE'],
            'deliveryTypes' => ['STANDARD_DELIVERY', 'DRONE_DELIVERY'],
            'options'       => capabilityOptions([
                'requiresSignature' => ['isRequired' => false, 'requires' => ['teleport'], 'excludes' => []],
                'teleport'          => ['isRequired' => true],
            ]),
        ]),
        capabilityResult(['carrier' => 'HOOPLA']),
    ];
}

it('caches an answer with values the SDK does not know, and reads it back', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r       = makeCapabilitiesRepository([new GuzzleResponse(200, [], capabilitiesBody(unknownValuesResults()))]);
    $request = CapabilitiesRequest::forCountry('NL');

    $fresh = $r['repository']->forStore(1, $request);

    // Drop the per-request memo, so the second read comes from the cache entry.
    setPrivateProperty($r['repository'], 'memo', []);
    $cached = $r['repository']->forStore(1, $request);

    $cacheEntries = array_values(array_filter($r['store']->entries, static function (string $entry): bool {
        return '1' !== $entry;
    }));

    expect($r['history'])->toHaveCount(1)
        // The raw answer, unknown values included.
        ->and(json_decode($cacheEntries[0], true))->toBe(unknownValuesResults());

    foreach ([$fresh, $cached] as $set) {
        expect($set->isPermissive())->toBeFalse()
            ->and($set->carriers())->toBe([Carrier::POSTNL, 'hoopla'])
            ->and($set->unknownValues())->toBe([
                'carrier'      => ['HOOPLA'],
                'packageType'  => ['HOVERCRATE'],
                'deliveryType' => ['DRONE_DELIVERY'],
            ])
            ->and($set->packageTypesFor(Carrier::POSTNL))->toBe([PackageType::PACKAGE_NAME])
            // Not offered until an SDK update: the module cannot send it.
            ->and($set->optionsFor(Carrier::POSTNL))->not->toContain('teleport')
            ->and($set->hasOption(Carrier::POSTNL, null, ShipmentOption::SIGNATURE))->toBeTrue()
            ->and($set->requiresFor(Carrier::POSTNL, null, ShipmentOption::SIGNATURE))->toBe([]);
    }
});
