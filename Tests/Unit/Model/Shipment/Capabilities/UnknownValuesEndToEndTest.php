<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

// A new carrier, package type, delivery type and option, through the real Client, cache and stored answers.

function unknownValuesResult(): array
{
    return capabilityResult([
        'carrier'       => 'HOOPLA',
        'packageTypes'  => ['PACKAGE', 'HOVERCRATE'],
        'deliveryTypes' => ['STANDARD_DELIVERY', 'DRONE_DELIVERY'],
        'options'       => capabilityOptions([
            'requiresSignature' => ['isRequired' => false, 'requires' => ['teleport'], 'excludes' => []],
            'teleport'          => ['isRequired' => true],
        ]),
    ]);
}

it('caches and stores an answer with values the SDK does not know, and reads it back', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r       = makeCapabilitiesRepository([new GuzzleResponse(200, [], capabilitiesBody([unknownValuesResult()]))]);
    $request = CapabilitiesRequest::forCountry('NL');

    $fresh = $r['repository']->forStore(1, $request);

    // Drop the per-request memo, so the second read comes from the cache entry.
    setPrivateProperty($r['repository'], 'memo', []);
    $cached = $r['repository']->forStore(1, $request);

    $cacheEntries = array_values(array_filter($r['store']->entries, static function (string $entry): bool {
        return '1' !== $entry;
    }));

    expect($r['history'])->toHaveCount(1)
        // The raw answer, unknown values included, in both the cache and the stored answers.
        ->and(json_decode($cacheEntries[0], true))->toBe([unknownValuesResult()])
        ->and(array_values($r['store']->stored))->toBe([[unknownValuesResult()]]);

    foreach ([$fresh, $cached] as $set) {
        expect($set->isPermissive())->toBeFalse()
            ->and($set->carriers())->toBe(['hoopla'])
            ->and($set->unknownValues())->toBe([
                'carrier'      => ['HOOPLA'],
                'packageType'  => ['HOVERCRATE'],
                'deliveryType' => ['DRONE_DELIVERY'],
            ])
            // Not offered until an SDK update: the module cannot send it.
            ->and($set->optionsFor('hoopla'))->not->toContain('teleport')
            ->and($set->hasOption('hoopla', null, ShipmentOption::SIGNATURE))->toBeTrue()
            ->and($set->requiresFor('hoopla', null, ShipmentOption::SIGNATURE))->toBe([]);
    }
});

it('serves the stored answer with values the SDK does not know when the API fails', function () {
    mockLoggerFacade()->shouldReceive('notice', 'warning')->byDefault();

    $r       = makeCapabilitiesRepository([
        new GuzzleResponse(200, [], capabilitiesBody([unknownValuesResult()])),
        new GuzzleResponse(500, [], ''),
    ]);
    $request = CapabilitiesRequest::forCountry('NL');

    $r['repository']->forStore(1, $request);

    // A new request: the cache is gone, the API fails, the stored answer remains.
    setPrivateProperty($r['repository'], 'memo', []);
    $r['store']->entries = [];

    $set = $r['repository']->forStore(1, $request);

    expect($r['history'])->toHaveCount(2)
        ->and($set->isPermissive())->toBeFalse()
        ->and($set->carriers())->toBe(['hoopla']);
});
