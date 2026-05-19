<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Proxy\Client;
use MyParcelNL\Magento\Service\Proxy\CorsHandler;

function makeCors(array $baseUrls): array
{
    $bag     = captureRawResult();
    $factory = mockRawFactoryReturning($bag['raw']);
    $manager = mockStoreManagerWithBaseUrls($baseUrls);
    return ['cors' => new CorsHandler($manager, $factory), 'bag' => $bag];
}

it('detects preflight only on OPTIONS with Access-Control-Request-Method', function () {
    $cors = makeCors(['https://shop.test/'])['cors'];

    expect($cors->isPreflight(mockProxyRequest('OPTIONS', ['Access-Control-Request-Method' => 'GET'])))->toBeTrue();
    expect($cors->isPreflight(mockProxyRequest('OPTIONS')))->toBeFalse();
    expect($cors->isPreflight(mockProxyRequest('GET', ['Access-Control-Request-Method' => 'GET'])))->toBeFalse();
});

it('prefers Origin over Referer and falls back when Origin is empty', function () {
    $cors = makeCors(['https://shop.test/'])['cors'];

    expect($cors->getRequestOrigin(mockProxyRequest('GET', [
        'Origin'  => 'https://a.test',
        'Referer' => 'https://b.test/page',
    ])))->toBe('https://a.test');

    expect($cors->getRequestOrigin(mockProxyRequest('GET', [
        'Referer' => 'https://b.test/page',
    ])))->toBe('https://b.test/page');

    expect($cors->getRequestOrigin(mockProxyRequest('GET')))->toBe('');
});

it('allows an origin matching any store base URL on scheme, host and port', function () {
    $cors = makeCors(['https://shop-a.test/', 'https://shop-b.test:8443/'])['cors'];

    expect($cors->isAllowedOrigin('https://shop-a.test'))->toBeTrue();
    expect($cors->isAllowedOrigin('https://shop-b.test:8443'))->toBeTrue();
});

it('rejects origins that differ in scheme, host or port', function () {
    $cors = makeCors(['https://shop.test/'])['cors'];

    expect($cors->isAllowedOrigin('http://shop.test'))->toBeFalse();         // wrong scheme
    expect($cors->isAllowedOrigin('https://evil.test'))->toBeFalse();        // wrong host
    expect($cors->isAllowedOrigin('https://shop.test:8443'))->toBeFalse();   // wrong explicit port
    expect($cors->isAllowedOrigin(''))->toBeFalse();
});

it('builds a 204 preflight response with the expected CORS headers', function () {
    ['cors' => $cors, 'bag' => $bag] = makeCors(['https://shop.test/']);

    $request = mockProxyRequest('OPTIONS', [
        'Access-Control-Request-Method'  => 'GET',
        'Access-Control-Request-Headers' => 'content-type, x-custom',
    ]);

    $result = $cors->buildPreflightResponse($request, 'https://shop.test');

    expect($result)->toBe($bag['raw']);

    $statusCalls = rawCallsTo($bag, 'setHttpResponseCode');
    expect($statusCalls)->toHaveCount(1);
    expect($statusCalls[0][0])->toBe(204);

    $headers = rawHeadersAsMap($bag);
    expect($headers['Access-Control-Allow-Origin'])->toBe('https://shop.test');
    expect($headers['Access-Control-Allow-Methods'])->toBe(implode(', ', Client::ALLOWED_METHODS));
    expect($headers['Access-Control-Allow-Headers'])->toBe('content-type, x-custom');
    expect($headers['Access-Control-Max-Age'])->toBe('600');
    expect($headers['Vary'])->toBe('Origin, Access-Control-Request-Method, Access-Control-Request-Headers');
});

it('falls back to a static Access-Control-Allow-Headers list when ACRH is absent', function () {
    ['cors' => $cors, 'bag' => $bag] = makeCors(['https://shop.test/']);

    $cors->buildPreflightResponse(
        mockProxyRequest('OPTIONS', ['Access-Control-Request-Method' => 'GET']),
        'https://shop.test'
    );

    $headers = rawHeadersAsMap($bag);
    expect($headers['Access-Control-Allow-Headers'])->toBe('Content-Type, Accept, Accept-Language');
});

it('applies Access-Control-Allow-Origin and appends Vary: Origin on a forwarded response', function () {
    $bag = captureRawResult();
    $cors = new CorsHandler(
        mockStoreManagerWithBaseUrls(['https://shop.test/']),
        mockRawFactoryReturning($bag['raw'])
    );

    $cors->applyCorsHeaders($bag['raw'], 'https://shop.test');

    $setHeaderCalls = rawCallsTo($bag, 'setHeader');
    expect($setHeaderCalls)->toHaveCount(2);

    expect($setHeaderCalls[0][0])->toBe('Access-Control-Allow-Origin');
    expect($setHeaderCalls[0][1])->toBe('https://shop.test');
    expect($setHeaderCalls[0][2])->toBeTrue(); // replace

    expect($setHeaderCalls[1][0])->toBe('Vary');
    expect($setHeaderCalls[1][1])->toBe('Origin');
    expect($setHeaderCalls[1][2])->toBeFalse(); // append, do not clobber upstream Vary
});

it('does not set Access-Control-Allow-Credentials anywhere', function () {
    ['cors' => $cors, 'bag' => $bag] = makeCors(['https://shop.test/']);

    $cors->buildPreflightResponse(
        mockProxyRequest('OPTIONS', ['Access-Control-Request-Method' => 'GET']),
        'https://shop.test'
    );
    $cors->applyCorsHeaders($bag['raw'], 'https://shop.test');

    foreach (rawHeadersAsMap($bag) as $name => $_value) {
        expect(strtolower($name))->not->toBe('access-control-allow-credentials');
    }
});
