<?php

declare(strict_types=1);

use Magento\Framework\Controller\Result\Raw;
use MyParcelNL\Magento\Controller\Proxy\Forward;
use MyParcelNL\Magento\Service\Proxy\CorsHandler;
use MyParcelNL\Magento\Service\Proxy\Forwarder;

function makeForward(array $headers, string $method = 'GET'): array
{
    $request = mockProxyRequest($method, $headers);

    $cors = Mockery::mock(CorsHandler::class);
    $cors->shouldReceive('getRequestOrigin')->with($request)->andReturn($headers['Origin'] ?? ($headers['Referer'] ?? ''));

    $forwarder = Mockery::mock(Forwarder::class);

    $bag = captureRawResult();
    $factory = mockRawFactoryReturning($bag['raw']);

    return [
        'controller' => new Forward($request, $forwarder, $factory, $cors),
        'request'    => $request,
        'cors'       => $cors,
        'forwarder'  => $forwarder,
        'bag'        => $bag,
    ];
}

it('short-circuits a preflight from an allowed origin without forwarding upstream', function () {
    $f = makeForward(['Origin' => 'https://shop.test'], 'OPTIONS');

    $preflight = Mockery::mock(Raw::class);
    $f['cors']->shouldReceive('isPreflight')->with($f['request'])->andReturn(true);
    $f['cors']->shouldReceive('isAllowedOrigin')->with('https://shop.test')->andReturn(true);
    $f['cors']->shouldReceive('buildPreflightResponse')
        ->with($f['request'], 'https://shop.test')
        ->once()
        ->andReturn($preflight);
    $f['forwarder']->shouldNotReceive('forward');

    expect($f['controller']->execute())->toBe($preflight);
});

it('returns 403 problem+json for a preflight from a disallowed origin', function () {
    $f = makeForward(['Origin' => 'https://evil.test'], 'OPTIONS');

    $f['cors']->shouldReceive('isPreflight')->with($f['request'])->andReturn(true);
    $f['cors']->shouldReceive('isAllowedOrigin')->with('https://evil.test')->andReturn(false);
    $f['cors']->shouldNotReceive('buildPreflightResponse');
    $f['forwarder']->shouldNotReceive('forward');

    $result = $f['controller']->execute();

    expect($result)->toBe($f['bag']['raw']);
    $status = rawCallsTo($f['bag'], 'setHttpResponseCode');
    expect($status[0][0])->toBe(403);

    $headers = rawHeadersAsMap($f['bag']);
    expect($headers)->toHaveKey('Content-Type');
    expect($headers)->not->toHaveKey('Access-Control-Allow-Origin');
});

it('forwards and adds CORS headers for an actual request from an allowed origin', function () {
    $f = makeForward(['Origin' => 'https://shop.test'], 'GET');

    $forwarded = Mockery::mock(Raw::class);
    $f['cors']->shouldReceive('isPreflight')->with($f['request'])->andReturn(false);
    $f['cors']->shouldReceive('isAllowedOrigin')->with('https://shop.test')->andReturn(true);
    $f['forwarder']->shouldReceive('forward')->with($f['request'])->once()->andReturn($forwarded);
    $f['cors']->shouldReceive('applyCorsHeaders')->with($forwarded, 'https://shop.test')->once();

    expect($f['controller']->execute())->toBe($forwarded);
});

it('rejects an actual request from a disallowed origin without forwarding', function () {
    $f = makeForward(['Origin' => 'https://evil.test'], 'GET');

    $f['cors']->shouldReceive('isPreflight')->with($f['request'])->andReturn(false);
    $f['cors']->shouldReceive('isAllowedOrigin')->with('https://evil.test')->andReturn(false);
    $f['forwarder']->shouldNotReceive('forward');
    $f['cors']->shouldNotReceive('applyCorsHeaders');

    $result = $f['controller']->execute();

    expect($result)->toBe($f['bag']['raw']);
    expect(rawCallsTo($f['bag'], 'setHttpResponseCode')[0][0])->toBe(403);
});

it('rejects an actual request with no Origin and no Referer', function () {
    $f = makeForward([], 'GET');

    $f['cors']->shouldReceive('isPreflight')->with($f['request'])->andReturn(false);
    $f['cors']->shouldReceive('isAllowedOrigin')->with('')->andReturn(false);
    $f['forwarder']->shouldNotReceive('forward');

    $result = $f['controller']->execute();

    expect($result)->toBe($f['bag']['raw']);
    expect(rawCallsTo($f['bag'], 'setHttpResponseCode')[0][0])->toBe(403);
});

it('validateForCsrf is permissive and createCsrfValidationException returns null', function () {
    $f = makeForward([]);

    expect($f['controller']->validateForCsrf($f['request']))->toBeTrue();
    expect($f['controller']->createCsrfValidationException($f['request']))->toBeNull();
});
