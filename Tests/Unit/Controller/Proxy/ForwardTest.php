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

    return [
        'controller' => new Forward($request, $forwarder, $cors),
        'request'    => $request,
        'cors'       => $cors,
        'forwarder'  => $forwarder,
    ];
}

it('delegates preflight handling to CorsHandler regardless of origin', function () {
    $f = makeForward(['Origin' => 'https://shop.test'], 'OPTIONS');

    $preflight = Mockery::mock(Raw::class);
    $f['cors']->shouldReceive('isPreflight')->with($f['request'])->andReturn(true);
    $f['cors']->shouldReceive('buildPreflightResponse')
        ->with($f['request'], 'https://shop.test')
        ->once()
        ->andReturn($preflight);
    $f['cors']->shouldNotReceive('isAllowedOrigin');
    $f['forwarder']->shouldNotReceive('forward');

    expect($f['controller']->execute())->toBe($preflight);
});

it('returns the preflight response as-is for a disallowed origin (CorsHandler builds the 403)', function () {
    $f = makeForward(['Origin' => 'https://evil.test'], 'OPTIONS');

    $forbidden = Mockery::mock(Raw::class);
    $f['cors']->shouldReceive('isPreflight')->with($f['request'])->andReturn(true);
    $f['cors']->shouldReceive('buildPreflightResponse')
        ->with($f['request'], 'https://evil.test')
        ->once()
        ->andReturn($forbidden);
    $f['forwarder']->shouldNotReceive('forward');

    expect($f['controller']->execute())->toBe($forbidden);
});

it('forwards and applies CORS headers for an actual request from an allowed origin', function () {
    $f = makeForward(['Origin' => 'https://shop.test'], 'GET');

    $forwarded   = Mockery::mock(Raw::class);
    $withHeaders = Mockery::mock(Raw::class);
    $f['cors']->shouldReceive('isPreflight')->with($f['request'])->andReturn(false);
    $f['cors']->shouldReceive('isAllowedOrigin')->with('https://shop.test')->andReturn(true);
    $f['forwarder']->shouldReceive('forward')->with($f['request'])->once()->andReturn($forwarded);
    $f['cors']->shouldReceive('applyCorsHeaders')
        ->with($forwarded, 'https://shop.test')
        ->once()
        ->andReturn($withHeaders);

    expect($f['controller']->execute())->toBe($withHeaders);
});

it('rejects an actual request from a disallowed origin via CorsHandler::buildForbidden, without forwarding', function () {
    $f = makeForward(['Origin' => 'https://evil.test'], 'GET');

    $forbidden = Mockery::mock(Raw::class);
    $f['cors']->shouldReceive('isPreflight')->with($f['request'])->andReturn(false);
    $f['cors']->shouldReceive('isAllowedOrigin')->with('https://evil.test')->andReturn(false);
    $f['cors']->shouldReceive('buildForbidden')->once()->andReturn($forbidden);
    $f['forwarder']->shouldNotReceive('forward');
    $f['cors']->shouldNotReceive('applyCorsHeaders');

    expect($f['controller']->execute())->toBe($forbidden);
});

it('rejects an actual request with no Origin and no Referer without forwarding', function () {
    $f = makeForward([], 'GET');

    $forbidden = Mockery::mock(Raw::class);
    $f['cors']->shouldReceive('isPreflight')->with($f['request'])->andReturn(false);
    $f['cors']->shouldReceive('isAllowedOrigin')->with('')->andReturn(false);
    $f['cors']->shouldReceive('buildForbidden')->once()->andReturn($forbidden);
    $f['forwarder']->shouldNotReceive('forward');

    expect($f['controller']->execute())->toBe($forbidden);
});

it('validateForCsrf is permissive and createCsrfValidationException returns null', function () {
    $f = makeForward([]);

    expect($f['controller']->validateForCsrf($f['request']))->toBeTrue();
    expect($f['controller']->createCsrfValidationException($f['request']))->toBeNull();
});
