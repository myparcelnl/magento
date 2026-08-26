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
        ->withCarrier(Carrier::toV2Name(Carrier::POSTNL))
        ->withPackageType(PackageType::toV2Name(PackageType::PACKAGE_NAME));

    $c['client']->send(CAPABILITIES_TEST_API_KEY, $c['client']->serialize($request));

    /** @var GuzzleRequest $sent */
    $sent = $c['history'][0]['request'];
    $body = json_decode((string) $sent->getBody(), true);

    expect((string) $sent->getUri())->toBe('https://api.myparcel.nl/shipments/capabilities')
        ->and($sent->getMethod())->toBe('POST')
        ->and($sent->getHeaderLine('Accept'))->toBe('application/json;charset=utf-8;version=2')
        ->and($sent->getHeaderLine('Authorization'))->toBe('Bearer ' . base64_encode(CAPABILITIES_TEST_API_KEY))
        ->and($body['recipient']['countryCode'])->toBe('NL')
        ->and($body['carrier'])->toBe('POSTNL')
        ->and($body['packageType'])->toBe('PACKAGE');
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

it('keeps the insurance bounds a generated response model would have dropped', function () {
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

it('raises on a body carrying no results array', function () {
    $c = makeCapabilitiesClient([new GuzzleResponse(200, [], '{"unexpected":true}')]);

    expect(fn () => $c['client']->send(CAPABILITIES_TEST_API_KEY, '{}'))
        ->toThrow(RuntimeException::class, 'no results array');
});

it('logs an option the SDK request model cannot carry instead of dropping it silently', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('notice')
        ->once()
        ->with(Mockery::pattern('/dropped option\(s\).*fresh_food/'));

    $c    = makeCapabilitiesClient();
    $body = json_decode($c['client']->serialize(
        CapabilitiesRequest::forCountry('NL')->withOptions([ShipmentOption::FRESH_FOOD => true])
    ), true);

    expect($body['options'] ?? [])->not->toHaveKey('freshFood');
});

it('does not log when every option survives mapping', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('notice')->never();

    $c    = makeCapabilitiesClient();
    $body = json_decode($c['client']->serialize(
        CapabilitiesRequest::forCountry('NL')->withOptions([ShipmentOption::SIGNATURE => true])
    ), true);

    expect($body['options'])->toHaveKey('requiresSignature');
});

it('honours a host override, the only seam for testing against acceptance', function () {
    $c = makeCapabilitiesClient(
        [new GuzzleResponse(200, [], capabilitiesBody([]))],
        'https://api.acceptance.myparcel.nl'
    );

    $c['client']->send(CAPABILITIES_TEST_API_KEY, '{}');

    expect((string) $c['history'][0]['request']->getUri())
        ->toBe('https://api.acceptance.myparcel.nl/shipments/capabilities');
});
