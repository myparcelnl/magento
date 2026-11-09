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

it('sends the request the API expects: v2 keys, version=2 accept, the given key', function () {
    $c = makeCapabilitiesClient([new GuzzleResponse(200, [], capabilitiesBody([capabilityResult()]))]);

    $request = CapabilitiesRequest::forCountry('NL')
        ->withCarrier(Carrier::toV2Name('postnl'))
        ->withPackageType(PackageType::toV2Name(PackageType::PACKAGE_NAME));

    $c['client']->send(CAPABILITIES_TEST_API_KEY, $c['client']->serialize($request));

    /** @var GuzzleRequest $sent */
    $sent = $c['history'][0]['request'];
    $body = json_decode((string) $sent->getBody(), true);

    expect((string) $sent->getUri())->toBe('https://api.myparcel.nl/shipments/capabilities')
        ->and($sent->getMethod())->toBe('POST')
        ->and($sent->getHeaderLine('Accept'))->toBe('application/json;charset=utf-8;version=2')
        ->and($sent->getHeaderLine('Authorization'))->toBe('Bearer ' . base64_encode(CAPABILITIES_TEST_API_KEY))
        // This call builds its own header rather than going through the SDK trait, so it is the one
        // that silently drifts. It used to send a format of its own: MyParcelMagento/5.9.0; PHP/8.x.
        ->and($sent->getHeaderLine('User-Agent'))->toBe(createUserAgent()->header())
        // Without it the API answers `tracked` and never offers `noTracking`.
        ->and($sent->getHeaderLine('x-dmp-no-tracking'))->toBe('true')
        ->and($body['recipient']['countryCode'])->toBe('NL')
        ->and($body['carrier'])->toBe('POSTNL')
        ->and($body['packageType'])->toBe('PACKAGE');
});

it('sends the feature flags on the contract definitions request too', function () {
    $c = makeCapabilitiesClient([new GuzzleResponse(200, [], contractDefinitionsBody([contractDefinitionItem()]))]);

    $c['client']->sendContractDefinitions(CAPABILITIES_TEST_API_KEY);

    expect($c['history'][0]['request']->getHeaderLine('x-dmp-no-tracking'))->toBe('true');
});

it('returns the results array verbatim, keys the SDK does not declare included', function () {
    $result = capabilityResult(['options' => [
        'requiresSignature' => [],
        'aBrandNewOption'   => ['isRequired' => true],
    ]]);

    $c = makeCapabilitiesClient([new GuzzleResponse(200, [], capabilitiesBody([$result]))]);

    $results = $c['client']->send(CAPABILITIES_TEST_API_KEY, '{}');

    expect($results[0]['options'])->toHaveKey('aBrandNewOption')
        ->and($results[0]['options']['aBrandNewOption'])->toBe(['isRequired' => true]);
});

it('keeps the insurance bounds in the raw results', function () {
    $c = makeCapabilitiesClient([new GuzzleResponse(200, [], capabilitiesBody([capabilityResult()]))]);

    $results = $c['client']->send(CAPABILITIES_TEST_API_KEY, '{}');

    expect($results[0]['options']['insurance']['min']['amount'])->toBe(0)
        ->and($results[0]['options']['insurance']['max']['amount'])->toBe(500000)
        ->and($results[0]['options']['insurance']['default']['amount'])->toBe(10000);
});

it('raises on a non-2xx status', function () {
    $c = makeCapabilitiesClient([new GuzzleResponse(500, [], 'nope')]);

    expect(fn () => $c['client']->send(CAPABILITIES_TEST_API_KEY, '{}'))
        ->toThrow(RuntimeException::class, 'capabilities responded 500');
});

it('raises on a transport failure', function () {
    $c = makeCapabilitiesClient([new ConnectException('timed out', new GuzzleRequest('POST', '/'))]);

    expect(fn () => $c['client']->send(CAPABILITIES_TEST_API_KEY, '{}'))
        ->toThrow(RuntimeException::class);
});

it('names the decoding error for a 2xx body that is not JSON, not the status', function () {
    $c = makeCapabilitiesClient([new GuzzleResponse(200, [], 'not json')]);

    try {
        $c['client']->send(CAPABILITIES_TEST_API_KEY, '{}');
        $message = null;
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('Error JSON decoding')
        ->and($message)->not->toContain('responded 200');
});

it('raises on a body carrying no results array', function () {
    $c = makeCapabilitiesClient([new GuzzleResponse(200, [], '{"unexpected":true}')]);

    expect(fn () => $c['client']->send(CAPABILITIES_TEST_API_KEY, '{}'))
        ->toThrow(RuntimeException::class, 'no results array');
});

it('sends the serialized body unchanged through the generated client', function () {
    $c = makeCapabilitiesClient([new GuzzleResponse(200, [], capabilitiesBody([]))]);

    $body = $c['client']->serialize(
        CapabilitiesRequest::forCountry('NL')->withOptions([ShipmentOption::SIGNATURE => null])
    );

    $c['client']->send(CAPABILITIES_TEST_API_KEY, $body);

    // As objects, so an option's {} does not pass as [].
    expect(json_decode((string) $c['history'][0]['request']->getBody()))
        ->toEqual(json_decode($body));
});

it('waits at most two seconds for a connection, so a checkout does not hang on an unreachable host', function () {
    $c = makeCapabilitiesClient([new GuzzleResponse(200, [], capabilitiesBody([]))]);

    $c['client']->send(CAPABILITIES_TEST_API_KEY, '{}');

    expect($c['history'][0]['options']['connect_timeout'])->toBe(2);
});

it('refuses an empty API key before the SDK can read one from the environment', function () {
    $c = makeCapabilitiesClient([new GuzzleResponse(200, [], capabilitiesBody([]))]);

    expect(fn () => $c['client']->send('', '{}'))->toThrow(RuntimeException::class, 'no API key')
        ->and($c['history'])->toBeEmpty();
});

// ---- failures -------------------------------------------------------------

it('does not retry a throttled call, so the Repository can fall back at once', function () {
    $c = makeCapabilitiesClient([
        new GuzzleResponse(429, ['Retry-After' => '0'], ''),
        new GuzzleResponse(200, [], capabilitiesBody([])),
    ]);

    expect(fn () => $c['client']->send(CAPABILITIES_TEST_API_KEY, '{}'))
        ->toThrow(RuntimeException::class, 'capabilities responded 429')
        ->and($c['history'])->toHaveCount(1);
});

it('throws on a transport failure', function () {
    $c = makeCapabilitiesClient([new ConnectException('timed out', new GuzzleRequest('POST', '/'))]);

    expect(fn () => $c['client']->send(CAPABILITIES_TEST_API_KEY, '{}'))
        ->toThrow(RuntimeException::class, 'capabilities request failed');
});

it('asks contract definitions on their own path, unfiltered, in one call', function () {
    $c = makeCapabilitiesClient([
        new GuzzleResponse(200, [], contractDefinitionsBody([contractDefinitionItem()])),
    ]);

    $c['client']->sendContractDefinitions(CAPABILITIES_TEST_API_KEY);

    /** @var GuzzleRequest $sent */
    $sent = $c['history'][0]['request'];

    expect((string) $sent->getUri())
        ->toBe('https://api.myparcel.nl/shipments/capabilities/contract-definitions')
        // No carrier filter: one call answers for every carrier the account has, and each item
        // names its own. Filtering cost a request per carrier.
        ->and((string) $sent->getBody())->toBe('{}')
        ->and($sent->getHeaderLine('Accept'))->toBe('application/json;charset=utf-8;version=2')
        ->and($sent->getHeaderLine('Authorization'))
        ->toBe('Bearer ' . base64_encode(CAPABILITIES_TEST_API_KEY));
});

it('reads contract definitions out of items, not results', function () {
    $c = makeCapabilitiesClient([
        new GuzzleResponse(200, [], contractDefinitionsBody([contractDefinitionItem()])),
    ]);

    $items = $c['client']->sendContractDefinitions(CAPABILITIES_TEST_API_KEY);

    expect($items)->toHaveCount(1)
        ->and($items[0]['options']['insurance']['max']['amount'])->toBe(500000);
});

it('refuses a contract-definitions body carrying a results array instead of items', function () {
    $c = makeCapabilitiesClient([
        new GuzzleResponse(200, [], capabilitiesBody([capabilityResult()])),
    ]);

    expect(fn () => $c['client']->sendContractDefinitions(CAPABILITIES_TEST_API_KEY))
        ->toThrow(RuntimeException::class, 'contract definitions response carried no items array');
});

it('names contract definitions in its own error, not capabilities', function () {
    $c = makeCapabilitiesClient([new GuzzleResponse(500, [], '')]);

    expect(fn () => $c['client']->sendContractDefinitions(CAPABILITIES_TEST_API_KEY))
        ->toThrow(RuntimeException::class, 'contract definitions responded 500');
});
