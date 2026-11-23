<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Magento\Framework\App\Config\ScopeConfigInterface;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Model\Account\Account;
use MyParcelNL\Sdk\Model\Account\Shop;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;
use MyParcelNL\Sdk\Support\Collection;

// A new carrier, package type, delivery type and option, through the real Client and cache.

/** @return array[] */
function unknownValuesResults(): array
{
    return [
        capabilityResult([
            'packageTypes'  => ['PACKAGE', 'HOVERCRATE'],
            'deliveryTypes' => ['STANDARD_DELIVERY', 'DRONE_DELIVERY'],
            'options'       => capabilityOptions([
                'requiresSignature' => ['isRequired' => false],
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

    $fresh = $r['repository']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    // Drop the per-request memo, so the second read comes from the cache entry.
    setPrivateProperty($r['repository'], 'memo', []);
    $cached = $r['repository']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    $cacheEntries = array_values(array_filter($r['store']->entries, static function (string $entry): bool {
        return '1' !== $entry;
    }));

    expect($r['history'])->toHaveCount(1)
        // The raw answer, unknown values included.
        ->and(json_decode($cacheEntries[0], true))->toBe(unknownValuesResults());

    foreach ([$fresh, $cached] as $set) {
        expect($set->isPermissive())->toBeFalse()
            ->and($set->carriers())->toBe([Carrier::POSTNL])
            ->and($set->unknownValues())->toBe([
                'carrier'      => ['HOOPLA'],
                'packageType'  => ['HOVERCRATE'],
                'deliveryType' => ['DRONE_DELIVERY'],
            ])
            ->and($set->packageTypesFor(Carrier::POSTNL))->toBe([PackageType::PACKAGE_NAME])
            // Not offered until an SDK update: the module cannot send it.
            ->and($set->optionsFor(Carrier::POSTNL))->not->toContain('teleport')
            ->and($set->hasOption(Carrier::POSTNL, null, ShipmentOption::SIGNATURE))->toBeTrue();
    }
});

it('stores contract definitions with values the SDK does not know, and reads them back', function () {
    $items = [
        contractDefinitionItem([
            'packageTypes'  => ['PACKAGE', 'HOVERCRATE'],
            'deliveryTypes' => ['STANDARD_DELIVERY', 'DRONE_DELIVERY'],
            'options'       => capabilityOptions(['teleport' => ['isRequired' => true]]),
        ]),
        contractDefinitionItem(['carrier' => 'HOOPLA']),
    ];

    $client   = makeCapabilitiesClient([new GuzzleResponse(200, [], contractDefinitionsBody($items))]);
    $importer = importerFor([], $client['client']);

    // importFor() calls the SDK account service directly, so the row is built from its two halves.
    $definitions = invokePrivateMethod($importer, 'fetchContractDefinitions', ['live-key']);
    $row         = json_encode(invokePrivateMethod($importer, 'createArray', [new Collection([
        'shop'                 => new Shop(['id' => 42, 'name' => 'Test Shop']),
        'account'              => new Account(['id' => 7, 'platform_id' => 1, 'shops' => [], 'general_settings' => []]),
        'contract_definitions' => $definitions,
    ])]));

    $contracts = contractDefinitionsFor(
        [settingsPathFor('live-key') => $row],
        [ScopeConfigInterface::SCOPE_TYPE_DEFAULT => [0 => [Config::XML_PATH_API_KEY => 'live-key']]]
    );
    $set = $contracts->forApiKey('live-key');

    expect(json_decode($row, true)['contract_definitions'])->toBe($items)
        ->and($set->isPermissive())->toBeFalse()
        ->and($set->carriers())->toBe([Carrier::POSTNL])
        ->and($set->unknownValues())->toBe([
            'carrier'      => ['HOOPLA'],
            'packageType'  => ['HOVERCRATE'],
            'deliveryType' => ['DRONE_DELIVERY'],
        ])
        ->and($set->optionsFor(Carrier::POSTNL))->not->toContain('teleport')
        ->and($contracts->insuranceRangeFor(Carrier::POSTNL, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0)->max())
        ->toBe(5000);
});
