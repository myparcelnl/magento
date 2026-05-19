<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Magento\Framework\App\RequestInterface;
use MyParcelNL\Magento\Controller\Proxy\Forward;
use MyParcelNL\Magento\Model\Rest\ProblemDetails;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Proxy\Client;
use MyParcelNL\Magento\Service\Proxy\CorsHandler;
use MyParcelNL\Magento\Service\Proxy\Forwarder;
use MyParcelNL\Magento\Tests\Stub\RequestWithHeaders;
use Psr\Log\LoggerInterface;

/**
 * Build the real Forward + CorsHandler + Forwarder + Client graph, with
 * Guzzle backed by a MockHandler so the test never touches the network.
 * Only the seams to Magento's framework are mocked (Config, StoreManager,
 * RawFactory). This catches DI-wiring regressions that the per-class unit
 * tests cannot.
 */
function makeForwardStack(
    RequestInterface $request,
    array $responses,
    array $storeBaseUrls,
    string $apiKey = 'integration-key'
): array {
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getGeneralConfig')->with('api/key')->andReturn($apiKey);

    $logger = Mockery::mock(LoggerInterface::class);
    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
        $logger->shouldReceive($level)->byDefault();
    }

    $history     = [];
    $mockHandler = new MockHandler($responses);
    $stack       = HandlerStack::create($mockHandler);
    $stack->push(Middleware::history($history));
    $httpClient  = new GuzzleClient(['handler' => $stack]);

    $client       = new Client($config, $logger, $httpClient);
    $bag          = captureRawResult();
    $rawFactory   = mockRawFactoryReturning($bag['raw']);
    $storeManager = mockStoreManagerWithBaseUrls($storeBaseUrls);
    $cors         = new CorsHandler($storeManager, $rawFactory);
    $forwarder    = new Forwarder($client, $rawFactory);

    return [
        'controller' => new Forward($request, $forwarder, $rawFactory, $cors),
        'history'    => &$history,
        'bag'        => $bag,
    ];
}

/**
 * Mock a fully-featured RequestInterface that the Forward controller and
 * its delegates can interrogate end-to-end (method, headers, params,
 * server vars, body, header list).
 *
 * @param array<string,string> $headers
 * @param array<string,mixed>  $params  upstream_host / _acceptance / _path
 */
function mockIntegrationRequest(string $method, array $headers, array $params = []): RequestInterface
{
    $lowercased = [];
    foreach ($headers as $name => $value) {
        $lowercased[strtolower($name)] = $value;
    }

    $request = Mockery::mock(RequestInterface::class . ',' . RequestWithHeaders::class);
    $request->shouldReceive('getMethod')->andReturn($method);
    $request->shouldReceive('getContent')->andReturn('');
    $request->shouldReceive('getHeader')->andReturnUsing(
        static function (string $name) use ($lowercased) {
            return $lowercased[strtolower($name)] ?? false;
        }
    );
    $request->shouldReceive('getParam')->andReturnUsing(
        static function (string $name) use ($params) {
            return $params[$name] ?? null;
        }
    );
    $request->shouldReceive('getServer')->andReturnUsing(
        static function (string $name, $default = null) {
            return $default;
        }
    );

    $headerObjects = [];
    foreach ($headers as $name => $value) {
        $h = Mockery::mock();
        $h->shouldReceive('getFieldName')->andReturn($name);
        $h->shouldReceive('getFieldValue')->andReturn($value);
        $headerObjects[] = $h;
    }
    $request->shouldReceive('getHeaders')->andReturn($headerObjects);

    return $request;
}

it('round-trips a real GET through Forward + CorsHandler + Forwarder + Client', function () {
    $request = mockIntegrationRequest(
        'GET',
        ['Origin' => 'https://shop.test', 'Accept' => 'application/json'],
        [
            'upstream_host'       => 'core',
            'upstream_acceptance' => false,
            'upstream_path'       => 'shipments/capabilities',
        ]
    );

    $stack = makeForwardStack(
        $request,
        [new GuzzleResponse(200, ['Content-Type' => 'application/json'], '{"ok":true}')],
        ['https://shop.test/']
    );

    $result = $stack['controller']->execute();

    // The controller returns the same Raw the factory issued.
    expect($result)->toBe($stack['bag']['raw']);

    // Exactly one upstream HTTP call was issued, hitting the production
    // core host at the allow-listed path with the injected Authorization
    // and without the inbound Cookie/Authorization (none supplied here).
    expect($stack['history'])->toHaveCount(1);
    /** @var GuzzleRequest $sent */
    $sent = $stack['history'][0]['request'];
    expect((string) $sent->getUri())->toBe('https://api.myparcel.nl/shipments/capabilities');
    expect($sent->getMethod())->toBe('GET');
    expect($sent->getHeaderLine('Authorization'))->toBe('Bearer ' . base64_encode('integration-key'));

    // Forward propagates status + upstream body, and CorsHandler tacks on
    // Access-Control-Allow-Origin + Vary: Origin.
    expect(rawCallsTo($stack['bag'], 'setHttpResponseCode')[0][0])->toBe(200);
    expect(rawCallsTo($stack['bag'], 'setContents')[0][0])->toBe('{"ok":true}');

    $headers = rawHeadersAsMap($stack['bag']);
    expect($headers['Access-Control-Allow-Origin'])->toBe('https://shop.test');
    expect($headers['Vary'])->toBe('Origin');
    expect($headers['Content-Type'])->toBe('application/json');
});

it('answers a preflight from an allowed origin with 204 and the documented CORS headers, never hitting Guzzle', function () {
    $request = mockIntegrationRequest(
        'OPTIONS',
        [
            'Origin'                         => 'https://shop.test',
            'Access-Control-Request-Method'  => 'GET',
            'Access-Control-Request-Headers' => 'content-type, accept',
        ],
        [
            'upstream_host'       => 'core',
            'upstream_acceptance' => false,
            'upstream_path'       => 'shipments/capabilities',
        ]
    );

    $stack = makeForwardStack($request, [], ['https://shop.test/']);

    $result = $stack['controller']->execute();

    expect($result)->toBe($stack['bag']['raw']);
    expect($stack['history'])->toHaveCount(0);

    expect(rawCallsTo($stack['bag'], 'setHttpResponseCode')[0][0])->toBe(204);

    $headers = rawHeadersAsMap($stack['bag']);
    expect($headers['Access-Control-Allow-Origin'])->toBe('https://shop.test');
    expect($headers['Access-Control-Allow-Methods'])->toBe(implode(', ', Client::ALLOWED_METHODS));
    expect($headers['Access-Control-Allow-Headers'])->toBe('content-type, accept');
    expect($headers['Access-Control-Max-Age'])->toBe('600');
});

it('returns 403 application/problem+json end-to-end when the origin is not in the store list', function () {
    $request = mockIntegrationRequest(
        'GET',
        ['Origin' => 'https://evil.test'],
        [
            'upstream_host'       => 'core',
            'upstream_acceptance' => false,
            'upstream_path'       => 'shipments/capabilities',
        ]
    );

    $stack = makeForwardStack($request, [], ['https://shop.test/']);

    $stack['controller']->execute();

    expect($stack['history'])->toHaveCount(0);
    expect(rawCallsTo($stack['bag'], 'setHttpResponseCode')[0][0])->toBe(403);

    $headers = rawHeadersAsMap($stack['bag']);
    expect($headers['Content-Type'])->toBe(ProblemDetails::CONTENT_TYPE);
    expect($headers)->not->toHaveKey('Access-Control-Allow-Origin');
});
