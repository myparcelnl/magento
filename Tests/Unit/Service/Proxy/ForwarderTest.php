<?php

declare(strict_types=1);

use Magento\Framework\App\RequestInterface;
use MyParcelNL\Magento\Service\Proxy\Client;
use MyParcelNL\Magento\Service\Proxy\Forwarder;
use MyParcelNL\Magento\Service\Proxy\Response;
use MyParcelNL\Magento\Tests\Stub\RequestWithHeaders;

/**
 * Build a forwarder with a mocked Client and a recording Raw result.
 *
 * Returns ['forwarder', 'client', 'bag'] where 'bag' is a captureRawResult() bag.
 */
function makeForwarder(): array
{
    $client  = Mockery::mock(Client::class);
    $bag     = captureRawResult();
    $factory = mockRawFactoryReturning($bag['raw']);

    return [
        'forwarder' => new Forwarder($client, $factory),
        'client'    => $client,
        'bag'       => $bag,
    ];
}

/**
 * Build a RequestInterface mock for the Forwarder under test.
 *
 * @param array<string,mixed>  $params         upstream_host/_acceptance/_path
 * @param array<string,string> $headers        name => value
 * @param array<string,string> $serverParams   key => value, e.g. ['QUERY_STRING' => 'a=1']
 */
function mockForwarderRequest(
    string $method,
    array $params,
    string $body = '',
    array $headers = [],
    array $serverParams = []
): RequestInterface {
    // Combine RequestInterface with a stub interface that declares
    // getHeaders() so the Forwarder's method_exists guard sees it.
    $request = Mockery::mock(RequestInterface::class . ',' . RequestWithHeaders::class);
    $request->shouldReceive('getMethod')->andReturn($method);
    $request->shouldReceive('getContent')->andReturn($body);
    $request->shouldReceive('getParam')->andReturnUsing(
        static function (string $name) use ($params) {
            return $params[$name] ?? null;
        }
    );
    $request->shouldReceive('getServer')->andReturnUsing(
        static function (string $name, $default = null) use ($serverParams) {
            return $serverParams[$name] ?? $default;
        }
    );

    $headerObjects = [];
    foreach ($headers as $name => $value) {
        $header = Mockery::mock();
        $header->shouldReceive('getFieldName')->andReturn($name);
        $header->shouldReceive('getFieldValue')->andReturn($value);
        $headerObjects[] = $header;
    }
    $request->shouldReceive('getHeaders')->andReturn($headerObjects);

    return $request;
}

it('passes router-parsed host, acceptance, path, method and body to Client::forward', function () {
    $f = makeForwarder();

    $request = mockForwarderRequest(
        'POST',
        [
            'upstream_host'        => 'core',
            'upstream_acceptance'  => true,
            'upstream_path'        => 'shipments/capabilities',
        ],
        '{"x":1}'
    );

    $captured = [];
    $f['client']
        ->shouldReceive('forward')
        ->withArgs(function ($host, $acceptance, $path, $method, $headers, $body, $query) use (&$captured) {
            $captured = compact('host', 'acceptance', 'path', 'method', 'body');
            return true;
        })
        ->once()
        ->andReturn(new Response(200, [], ''));

    $f['forwarder']->forward($request);

    expect($captured)->toBe([
        'host'       => 'core',
        'acceptance' => true,
        'path'       => 'shipments/capabilities',
        'method'     => 'POST',
        'body'       => '{"x":1}',
    ]);
});

it('reads the query string from RequestInterface::getServer, not $_SERVER', function () {
    $f = makeForwarder();

    $request = mockForwarderRequest(
        'GET',
        [
            'upstream_host'        => 'core',
            'upstream_acceptance'  => false,
            'upstream_path'        => 'shipments/capabilities',
        ],
        '',
        [],
        ['QUERY_STRING' => 'carrier=postnl&country=NL']
    );

    // Make sure $_SERVER does NOT contain the value, so we know the
    // forwarder consulted the request abstraction.
    unset($_SERVER['QUERY_STRING']);

    $capturedQuery = null;
    $f['client']
        ->shouldReceive('forward')
        ->withArgs(function ($host, $acceptance, $path, $method, $headers, $body, $query) use (&$capturedQuery) {
            $capturedQuery = $query;
            return true;
        })
        ->once()
        ->andReturn(new Response(200, [], ''));

    $f['forwarder']->forward($request);

    expect($capturedQuery)->toBe('carrier=postnl&country=NL');
});

it('collects request headers into a name => value map for Client::forward', function () {
    $f = makeForwarder();

    $request = mockForwarderRequest(
        'GET',
        [
            'upstream_host'        => 'core',
            'upstream_acceptance'  => false,
            'upstream_path'        => 'shipments/capabilities',
        ],
        '',
        [
            'Accept'          => 'application/json',
            'Accept-Language' => 'nl',
        ]
    );

    $captured = null;
    $f['client']
        ->shouldReceive('forward')
        ->withArgs(function ($host, $acceptance, $path, $method, $headers, $body, $query) use (&$captured) {
            $captured = $headers;
            return true;
        })
        ->once()
        ->andReturn(new Response(200, [], ''));

    $f['forwarder']->forward($request);

    expect($captured)->toBe([
        'Accept'          => 'application/json',
        'Accept-Language' => 'nl',
    ]);
});

it('wraps the upstream Response (status, headers, body) onto the Raw result', function () {
    $f = makeForwarder();

    $request = mockForwarderRequest(
        'GET',
        [
            'upstream_host'        => 'core',
            'upstream_acceptance'  => false,
            'upstream_path'        => 'shipments/capabilities',
        ]
    );

    $f['client']->shouldReceive('forward')->andReturn(new Response(
        201,
        ['Content-Type' => 'application/json', 'X-Trace' => 'abc'],
        '{"ok":true}'
    ));

    $result = $f['forwarder']->forward($request);

    expect($result)->toBe($f['bag']['raw']);

    expect(rawCallsTo($f['bag'], 'setHttpResponseCode')[0][0])->toBe(201);

    $headers = rawHeadersAsMap($f['bag']);
    expect($headers['Content-Type'])->toBe('application/json');
    expect($headers['X-Trace'])->toBe('abc');

    expect(rawCallsTo($f['bag'], 'setContents')[0][0])->toBe('{"ok":true}');
});

it('passes an empty query string when the request has none', function () {
    $f = makeForwarder();

    $request = mockForwarderRequest(
        'GET',
        [
            'upstream_host'        => 'core',
            'upstream_acceptance'  => false,
            'upstream_path'        => 'shipments/capabilities',
        ]
    );

    unset($_SERVER['QUERY_STRING']);

    $capturedQuery = null;
    $f['client']
        ->shouldReceive('forward')
        ->withArgs(function ($host, $acceptance, $path, $method, $headers, $body, $query) use (&$capturedQuery) {
            $capturedQuery = $query;
            return true;
        })
        ->once()
        ->andReturn(new Response(200, [], ''));

    $f['forwarder']->forward($request);

    expect($capturedQuery)->toBe('');
});
