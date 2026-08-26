<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

// Fixtures live in Tests/Helpers/{CapabilitiesFixtures,HttpMocks}.php.

function capabilitiesOk(?array $results = null): GuzzleResponse
{
    return new GuzzleResponse(200, [], capabilitiesBody($results ?? [capabilityResult()]));
}

it('fetches once and serves the cache after that', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r       = makeCapabilitiesRepository([capabilitiesOk()]);
    $request = CapabilitiesRequest::forCountry('NL');

    $first  = $r['repository']->forStore(1, $request);
    $second = $r['repository']->forStore(1, $request);

    expect($first->carriers())->toBe([Carrier::POSTNL])
        ->and($second->carriers())->toBe([Carrier::POSTNL])
        ->and($r['history'])->toHaveCount(1);
});

it('misses the cache when any request field changes', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r = makeCapabilitiesRepository([capabilitiesOk(), capabilitiesOk(), capabilitiesOk()]);

    $r['repository']->forStore(1, CapabilitiesRequest::forCountry('NL'));
    $r['repository']->forStore(1, CapabilitiesRequest::forCountry('BE'));
    $r['repository']->forStore(1, CapabilitiesRequest::forCountry('NL')
        ->withPackageType(PackageType::toV2Name(PackageType::MAILBOX_NAME)));

    expect($r['history'])->toHaveCount(3)
        ->and($r['store']->entries)->toHaveCount(3);
});

it('keeps two accounts apart, and never serves one from the other', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $dpdOnly = capabilityResult(['carrier' => 'DPD', 'options' => ['requiresSignature' => []]]);

    $r = makeCapabilitiesRepository([capabilitiesOk(), capabilitiesOk([$dpdOnly])]);

    $request = CapabilitiesRequest::forCountry('NL');
    $first   = $r['repository']->forApiKey('key-one', $request);
    $second  = $r['repository']->forApiKey('key-two', $request);

    expect($first->carriers())->toBe([Carrier::POSTNL])
        ->and($second->carriers())->toBe([Carrier::DPD])
        ->and($r['history'])->toHaveCount(2);
});

it('never writes the api key anywhere, in the cache id or the tags', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r = makeCapabilitiesRepository([capabilitiesOk()]);
    $r['repository']->forStore(1, CapabilitiesRequest::forCountry('NL'));

    $written = json_encode([array_keys($r['store']->entries), $r['store']->savedTags]);

    expect($written)->not->toContain(CAPABILITIES_TEST_API_KEY);
});

it('logs a failure with a fingerprint prefix, never the key', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')
        ->once()
        ->with(Mockery::on(static function (string $message): bool {
            return false === strpos($message, CAPABILITIES_TEST_API_KEY)
                   && false !== strpos($message, 'Capabilities lookup failed');
        }));

    $r = makeCapabilitiesRepository([new GuzzleResponse(500, [], '')]);

    expect($r['repository']->forStore(1, CapabilitiesRequest::forCountry('NL'))->isPermissive())->toBeTrue();
});

it('fails open on a 500, a timeout and an undecodable body', function () {
    mockLoggerFacade()->shouldReceive('warning')->byDefault();

    $faults = [
        new GuzzleResponse(500, [], 'server error'),
        new ConnectException('timed out', new GuzzleRequest('POST', '/')),
        new GuzzleResponse(200, [], 'not json at all'),
    ];

    foreach ($faults as $index => $fault) {
        $r   = makeCapabilitiesRepository([$fault]);
        $set = $r['repository']->forStore(1, CapabilitiesRequest::forCountry('NL'));

        expect($set->isPermissive())->toBeTrue()
            ->and($set->hasOption(Carrier::POSTNL, null, ShipmentOption::AGE_CHECK))->toBeTrue();
    }
});

it('serves the cached answer when a later refresh would fail', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r       = makeCapabilitiesRepository([capabilitiesOk(), new GuzzleResponse(500, [], '')]);
    $request = CapabilitiesRequest::forCountry('NL');

    $r['repository']->forStore(1, $request);

    // A second Repository over the same cache: no memo, so it must come from the entry.
    $again = makeCapabilitiesRepository([new GuzzleResponse(500, [], '')]);
    $again['store']->entries = $r['store']->entries;

    $set = $again['repository']->forStore(1, $request);

    expect($set->isPermissive())->toBeFalse()
        ->and($set->carriers())->toBe([Carrier::POSTNL])
        ->and($again['history'])->toHaveCount(0);
});

it('writes the entry with no expiry, which is what makes stale-serving work', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r = makeCapabilitiesRepository([capabilitiesOk()]);
    $r['repository']->forStore(1, CapabilitiesRequest::forCountry('NL'));

    expect(array_values($r['store']->savedTags)[0]['lifeTime'])->toBeNull();
});

it('fails open for a store with no api key rather than stopping the page', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')->once()->with(Mockery::pattern('/No MyParcel API key for store 7/'));

    $r   = makeCapabilitiesRepository([], '');
    $set = $r['repository']->forStore(7, CapabilitiesRequest::forCountry('NL'));

    expect($set->isPermissive())->toBeTrue()
        ->and($r['history'])->toHaveCount(0);
});

it('logs each kind of unrecognised value once per fetch', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('notice')->once()->with(Mockery::pattern('/packageType value\(s\).*HOVERCRAFT/'));
    $logger->shouldReceive('notice')->once()->with(Mockery::pattern('/option value\(s\).*aBrandNewOption/'));

    $r = makeCapabilitiesRepository([capabilitiesOk([
        capabilityResult([
            'packageTypes' => ['PACKAGE', 'HOVERCRAFT'],
            'options'      => ['aBrandNewOption' => []],
        ]),
    ])]);

    $set = $r['repository']->forStore(1, CapabilitiesRequest::forCountry('NL'));

    // Logged, and still usable: the recognised half of the response survives the unknown half.
    expect($set->packageTypesFor(Carrier::POSTNL))->toBe([PackageType::PACKAGE_NAME]);
});
