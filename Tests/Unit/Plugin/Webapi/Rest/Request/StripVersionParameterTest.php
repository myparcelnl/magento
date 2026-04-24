<?php

declare(strict_types=1);

use Magento\Framework\Webapi\Rest\Request;
use MyParcelNL\Magento\Plugin\Magento\Framework\Webapi\Rest\Request\StripVersionParameter;

function makePlugin(string $pathInfo): StripVersionParameter
{
    $request = Mockery::mock(Request::class);
    $request->shouldReceive('getPathInfo')->andReturn($pathInfo);

    return new StripVersionParameter($request);
}

function makeSubject(string $contentType): Request
{
    $headers = Mockery::mock(\Laminas\Http\Headers::class);
    $headers->shouldReceive('get')->andReturn(false);
    $headers->shouldReceive('addHeaderLine')->andReturnSelf();

    $subject = Mockery::mock(Request::class);
    $subject->shouldReceive('getHeader')->with('Content-Type')->andReturn($contentType);
    $subject->shouldReceive('getHeaders')->andReturn($headers);

    return $subject;
}

it('strips version parameter on MyParcel endpoints', function () {
    $plugin  = makePlugin('/rest/V1/myparcel/delivery-options');
    $subject = makeSubject('application/json;version=1');

    $result = $plugin->aroundGetContentType($subject, fn () => 'application/json');

    expect($result)->toBe('application/json');
});

it('preserves charset when stripping version', function () {
    $plugin  = makePlugin('/rest/V1/myparcel/delivery-options');
    $subject = makeSubject('application/json; version=2; charset=utf-8');

    $result = $plugin->aroundGetContentType($subject, fn () => 'application/json');

    expect($result)->toBe('application/json');
});

it('does not interfere with non-MyParcel endpoints', function () {
    $plugin  = makePlugin('/rest/V1/products');
    $subject = Mockery::mock(Request::class);

    $called = false;
    $result = $plugin->aroundGetContentType($subject, function () use (&$called) {
        $called = true;
        return 'application/json';
    });

    expect($called)->toBeTrue();
    expect($result)->toBe('application/json');
});

it('restores original header after proceed', function () {
    $original = 'application/json;version=1';
    $plugin   = makePlugin('/rest/V1/myparcel/delivery-options');

    $headers = Mockery::mock(\Laminas\Http\Headers::class);
    $headers->shouldReceive('get')->andReturn(false);
    $headers->shouldReceive('addHeaderLine')
        ->with('Content-Type', 'application/json')
        ->once()
        ->ordered();
    $headers->shouldReceive('addHeaderLine')
        ->with('Content-Type', $original)
        ->once()
        ->ordered();

    $subject = Mockery::mock(Request::class);
    $subject->shouldReceive('getHeader')->with('Content-Type')->andReturn($original);
    $subject->shouldReceive('getHeaders')->andReturn($headers);

    $plugin->aroundGetContentType($subject, fn () => 'application/json');
});

it('restores original header even when proceed throws', function () {
    $original = 'application/json;version=1';
    $plugin   = makePlugin('/rest/V1/myparcel/delivery-options');

    $headers = Mockery::mock(\Laminas\Http\Headers::class);
    $headers->shouldReceive('get')->andReturn(false);
    $headers->shouldReceive('addHeaderLine')
        ->with('Content-Type', 'application/json')
        ->once();
    $headers->shouldReceive('addHeaderLine')
        ->with('Content-Type', $original)
        ->once();

    $subject = Mockery::mock(Request::class);
    $subject->shouldReceive('getHeader')->with('Content-Type')->andReturn($original);
    $subject->shouldReceive('getHeaders')->andReturn($headers);

    expect(fn () => $plugin->aroundGetContentType($subject, function () {
        throw new \RuntimeException('boom');
    }))->toThrow(\RuntimeException::class);
});

it('handles version with v prefix', function () {
    $plugin  = makePlugin('/rest/V1/myparcel/delivery-options');
    $subject = makeSubject('application/json;version=v1');

    $result = $plugin->aroundGetContentType($subject, fn () => 'application/json');

    expect($result)->toBe('application/json');
});

it('handles version with minor components', function () {
    $plugin  = makePlugin('/rest/V1/myparcel/delivery-options');
    $subject = makeSubject('application/json; version=1.2');

    $result = $plugin->aroundGetContentType($subject, fn () => 'application/json');

    expect($result)->toBe('application/json');
});
