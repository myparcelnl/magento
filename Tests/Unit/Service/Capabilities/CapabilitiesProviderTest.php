<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyParcelNL\Magento\Service\Capabilities\CapabilitiesProvider;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

function capabilitiesOk(?array $results = null): GuzzleResponse
{
    return new GuzzleResponse(200, [], capabilitiesBody($results ?? [capabilityResult()]));
}

/**
 * The CapabilitiesProvider's own id prefixes, read off the class so a rename cannot quietly stop these tests
 * from asserting anything.
 *
 * @return array{0: string, 1: string} success prefix, failure prefix
 */
function capabilitiesCacheIdPrefixes(): array
{
    $class = new ReflectionClass(CapabilitiesProvider::class);

    return [$class->getConstant('CACHE_ID_PREFIX'), $class->getConstant('FAILURE_ID_PREFIX')];
}

it('fetches once and serves the cache after that', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r       = makeCapabilitiesProvider([capabilitiesOk()]);
    $request = CapabilitiesRequest::forCountry('NL');

    $first  = $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);
    $second = $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    expect($first->carriers())->toBe(['postnl'])
        ->and($second->carriers())->toBe(['postnl'])
        ->and($r['history'])->toHaveCount(1);
});

it('misses the cache when any request field changes', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r = makeCapabilitiesProvider([capabilitiesOk(), capabilitiesOk(), capabilitiesOk()]);

    $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'));
    $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('BE'));
    $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL')
        ->withPackageType(PackageType::toV2Name(PackageType::MAILBOX_NAME)));

    expect($r['history'])->toHaveCount(3)
        ->and($r['store']->entries)->toHaveCount(3);
});

it('keeps two accounts apart, and never serves one from the other', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $dpdOnly = capabilityResult(['carrier' => 'DPD', 'options' => ['requiresSignature' => []]]);

    $r = makeCapabilitiesProvider([capabilitiesOk(), capabilitiesOk([$dpdOnly])]);

    $request = CapabilitiesRequest::forCountry('NL');
    $first   = $r['provider']->forApiKey('key-one', $request);
    $second  = $r['provider']->forApiKey('key-two', $request);

    expect($first->carriers())->toBe(['postnl'])
        ->and($second->carriers())->toBe(['dpd'])
        ->and($r['history'])->toHaveCount(2);
});

it('never writes the api key anywhere, in the cache id or the tags', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r = makeCapabilitiesProvider([capabilitiesOk()]);
    $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'));

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

    $r = makeCapabilitiesProvider([new GuzzleResponse(500, [], '')]);

    expect($r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'))->isPermissive())->toBeTrue();
});

it('fails open on a 500, a timeout and an undecodable body', function () {
    mockLoggerFacade()->shouldReceive('warning')->byDefault();

    $faults = [
        new GuzzleResponse(500, [], 'server error'),
        new ConnectException('timed out', new GuzzleRequest('POST', '/')),
        new GuzzleResponse(200, [], 'not json at all'),
    ];

    foreach ($faults as $index => $fault) {
        $r   = makeCapabilitiesProvider([$fault]);
        $set = $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'));

        expect($set->isPermissive())->toBeTrue()
            ->and($set->hasOption('postnl', null, ShipmentOption::AGE_CHECK))->toBeTrue();
    }
});

it('serves the cached answer without a new request', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r       = makeCapabilitiesProvider([capabilitiesOk(), new GuzzleResponse(500, [], '')]);
    $request = CapabilitiesRequest::forCountry('NL');

    $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    // A second CapabilitiesProvider over the same cache: no memo, so it must come from the entry.
    $again = makeCapabilitiesProvider([new GuzzleResponse(500, [], '')]);
    $again['store']->entries = $r['store']->entries;

    $set = $again['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    expect($set->isPermissive())->toBeFalse()
        ->and($set->carriers())->toBe(['postnl'])
        ->and($again['history'])->toHaveCount(0);
});

it('writes the entry with no expiry', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r = makeCapabilitiesProvider([capabilitiesOk()]);
    $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'));

    expect(array_values($r['store']->savedTags)[0]['lifeTime'])->toBeNull();
});

it('logs each kind of unrecognised value once per fetch, and no option, which the SDK leaves out', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('notice')->once()->with(Mockery::pattern('/packageType value\(s\).*HOVERCRAFT/'));
    $logger->shouldReceive('notice')->never()->with(Mockery::pattern('/option value/'));

    $r = makeCapabilitiesProvider([capabilitiesOk([
        capabilityResult([
            'packageTypes' => ['PACKAGE', 'HOVERCRAFT'],
            'options'      => ['aBrandNewOption' => []],
        ]),
    ])]);

    $set = $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'));

    // Logged, and still usable: the recognised half of the response survives the unknown half.
    expect($set->packageTypesFor('postnl'))->toBe([PackageType::PACKAGE_NAME]);
});

// ---- not hammering a failing endpoint -------------------------------------

it('remembers a failed request briefly, so a reload does not repeat the burst', function () {
    mockLoggerFacade()->shouldReceive('warning');

    $r       = makeCapabilitiesProvider([new GuzzleResponse(429, [], '')]);
    $request = CapabilitiesRequest::forCountry('NL');

    $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    [, $failurePrefix] = capabilitiesCacheIdPrefixes();

    $failures = array_filter(
        $r['store']->savedTags,
        static fn (string $id): bool => 0 === strpos($id, $failurePrefix),
        ARRAY_FILTER_USE_KEY
    );

    expect($failures)->toHaveCount(1)
        ->and(array_values($failures)[0]['lifeTime'])->toBe(60);
});

it('does not call again while a failure is remembered', function () {
    mockLoggerFacade()->shouldReceive('warning')->once();

    // One queued response: a second call would exhaust the handler and fail loudly.
    $r       = makeCapabilitiesProvider([new GuzzleResponse(429, [], '')]);
    $request = CapabilitiesRequest::forCountry('NL');

    $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    // A fresh CapabilitiesProvider over the same cache: no memo, so only the failure entry can stop the call.
    $again = makeCapabilitiesProvider([]);
    $again['store']->entries = $r['store']->entries;

    expect($again['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request)->isPermissive())->toBeTrue()
        ->and($again['history'])->toHaveCount(0);
});

it('prefers a previous good answer over a recent failure', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();
    mockLoggerFacade()->shouldReceive('warning')->byDefault();

    $request = CapabilitiesRequest::forCountry('NL');

    // A store holding both a success and a failure entry for the same request.
    $ok = makeCapabilitiesProvider([capabilitiesOk()]);
    $ok['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    // The double stores ids exactly as the CapabilitiesProvider passes them, so lower case and unprefixed by
    // Magento. Add a failure marker beside the success entry for the same request.
    [$okPrefix, $failurePrefix] = capabilitiesCacheIdPrefixes();

    $entries = $ok['store']->entries;
    foreach (array_keys($entries) as $id) {
        expect($id)->toStartWith($okPrefix);
        $entries[$failurePrefix . substr($id, strlen($okPrefix))] = '1';
    }

    $both = makeCapabilitiesProvider([]);
    $both['store']->entries = $entries;

    $set = $both['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    expect($set->isPermissive())->toBeFalse()
        ->and($set->carriers())->toBe(['postnl'])
        ->and($both['history'])->toHaveCount(0);
});

/**
 * After a deploy or cache:clean every concurrent request misses the same request at once. Waiting for
 * the holder would queue them all behind one API call, so the rest answer as a failure would.
 */
it('does not call out for a request another request is already fetching', function () {
    $r = makeCapabilitiesProvider([capabilitiesOk()]);
    $r['store']->lockHeld = true;

    $set = $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'));

    expect($r['history'])->toHaveCount(0)
        ->and($set->isPermissive())->toBeTrue();
});

it('releases the fetch lock once it has the answer', function () {
    $r = makeCapabilitiesProvider([capabilitiesOk()]);

    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'));

    expect($r['store']->unlockedNames)->toBe($r['store']->lockedNames);
});

it('fails open when the cache or the lock backend throws', function (string $call) {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')->once()->with(Mockery::pattern('/Capabilities lookup failed.*refused the ' . $call . '/'));
    $logger->shouldReceive('notice')->byDefault();

    $r = makeCapabilitiesProvider([capabilitiesOk()]);
    $r['store']->throwOn = $call;

    expect($r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'))->isPermissive())->toBeTrue()
        // Releases the lock it took, and only that one.
        ->and($r['store']->unlockedNames)->toBe($r['store']->lockedNames);
})->with(['load', 'lock']);

it('fails open when the stored copy cannot be read', function () {
    $r = makeCapabilitiesProvider([]);
    $r['store']->lockHeld = true;
    $r['store']->throwOn  = 'storeLoad';

    expect($r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'))->isPermissive())->toBeTrue();
});

it('keeps the fetched answer when the cache refuses to save it', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')->once()->with(Mockery::pattern('/refused the save\. Serving the last stored answer/'));
    $logger->shouldReceive('notice')->byDefault();

    $r = makeCapabilitiesProvider([capabilitiesOk()]);
    $r['store']->throwOn = 'save';

    $set = $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'));

    expect($set->isPermissive())->toBeFalse()
        ->and($set->carriers())->toBe(['postnl'])
        ->and($r['store']->unlockedNames)->toBe($r['store']->lockedNames);
});

it('stores a copy of every answer it fetches', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r = makeCapabilitiesProvider([capabilitiesOk()]);
    $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'));

    expect($r['store']->stored)->toHaveCount(1)
        ->and(array_values($r['store']->stored)[0])->toBe([capabilityResult()]);
});

it('still answers when the copy cannot be stored', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')->once()->with(Mockery::pattern('/Could not store the capabilities answer/'));
    $logger->shouldReceive('notice')->byDefault();

    $r = makeCapabilitiesProvider([capabilitiesOk()]);
    $r['store']->throwOn = 'storeSave';

    $set = $r['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, CapabilitiesRequest::forCountry('NL'));

    expect($set->carriers())->toBe(['postnl'])
        ->and($r['store']->entries)->toHaveCount(1);
});

/**
 * After a cache:clean or a Redis restart only the stored copy is left. Whatever stops the fetch, the
 * request gets its last answer rather than everything.
 */
it('serves the stored answer when the cache is empty and it cannot fetch', function (Closure $cannotFetch) {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();
    mockLoggerFacade()->shouldReceive('warning')->byDefault();

    $request = CapabilitiesRequest::forCountry('NL');
    $first   = makeCapabilitiesProvider([capabilitiesOk()]);
    $first['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    [$okPrefix] = capabilitiesCacheIdPrefixes();
    $requestKey      = substr((string) array_key_first($first['store']->entries), strlen($okPrefix));

    $again = makeCapabilitiesProvider([new GuzzleResponse(500, [], '')]);
    $again['store']->stored = $first['store']->stored;
    $cannotFetch($again['store'], $requestKey);

    $set = $again['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    expect($set->isPermissive())->toBeFalse()
        ->and($set->carriers())->toBe(['postnl']);
})->with([
    'the fetch fails'          => [static function (): void {}],
    'another request fetches'  => [static function (object $store): void {
        $store->lockHeld = true;
    }],
    'a failure is remembered'  => [static function (object $store, string $requestKey): void {
        [, $failurePrefix] = capabilitiesCacheIdPrefixes();
        $store->entries[$failurePrefix . $requestKey] = '1';
    }],
    'the cache backend is down' => [static function (object $store): void {
        $store->throwOn = 'load';
    }],
]);

it('says in the log which answer a failed fetch served', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $request = CapabilitiesRequest::forCountry('NL');
    $first   = makeCapabilitiesProvider([capabilitiesOk()]);
    $first['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);

    $again = makeCapabilitiesProvider([new GuzzleResponse(500, [], '')]);
    $again['store']->stored = $first['store']->stored;

    mockLoggerFacade()->shouldReceive('warning')->once()->with(Mockery::pattern('/Serving the last stored answer\.$/'));

    $again['provider']->forApiKey(CAPABILITIES_TEST_API_KEY, $request);
});
