<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Model\Rest\ProblemDetails;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Proxy\Client;
use Psr\Log\LoggerInterface;

/**
 * Build a Client with a mocked Config, a permissive logger, and a Guzzle
 * client backed by a MockHandler. The returned `&history` array captures
 * each outgoing request so tests can assert URL, method, headers, body,
 * and Guzzle transfer options.
 *
 * @param GuzzleResponse[]|\Throwable[] $responses
 * @return array{client: Client, config: Config, logger: LoggerInterface, history: array, handler: MockHandler}
 */
function makeProxyClient(string $apiKey = 'test-api-key', array $responses = []): array
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getGeneralConfig')->with('api/key')->andReturn($apiKey);

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->byDefault();
    $logger->shouldReceive('error')->byDefault();
    $logger->shouldReceive('info')->byDefault();
    $logger->shouldReceive('debug')->byDefault();
    $logger->shouldReceive('notice')->byDefault();
    $logger->shouldReceive('critical')->byDefault();
    $logger->shouldReceive('alert')->byDefault();
    $logger->shouldReceive('emergency')->byDefault();
    $logger->shouldReceive('log')->byDefault();

    $history     = [];
    $mockHandler = new MockHandler($responses);
    $stack       = HandlerStack::create($mockHandler);
    $stack->push(Middleware::history($history));

    $httpClient = new GuzzleClient(['handler' => $stack]);

    return [
        'client'  => new Client($config, $logger, $httpClient),
        'config'  => $config,
        'logger'  => $logger,
        'history' => &$history,
        'handler' => $mockHandler,
    ];
}

function decodeProblem(string $body): array
{
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

// ---- rejection paths -------------------------------------------------------

it('rejects a disallowed method with 405 and an Allow header', function () {
    $c = makeProxyClient();

    $response = $c['client']->forward('core', false, 'shipments/capabilities', 'PUT', [], '', '');

    expect($response->status)->toBe(405);
    expect($response->headers['Content-Type'])->toBe(ProblemDetails::CONTENT_TYPE);
    expect($response->headers['Allow'])->toBe('GET, POST, HEAD, OPTIONS');
    expect(decodeProblem($response->body)['status'])->toBe(405);
    expect($c['history'])->toHaveCount(0);
});

it('rejects an unknown upstream host with 403', function () {
    $c = makeProxyClient();

    $response = $c['client']->forward('unknown', false, 'some/path', 'GET', [], '', '');

    expect($response->status)->toBe(403);
    expect(decodeProblem($response->body)['detail'])->toBe('host not allowed');
    expect($c['history'])->toHaveCount(0);
});

it('rejects a sub-path that is not in the host allow-list with 403', function () {
    $c = makeProxyClient();

    $response = $c['client']->forward('core', false, 'shipments/capabilities/extra', 'GET', [], '', '');

    expect($response->status)->toBe(403);
    expect(decodeProblem($response->body)['detail'])->toBe('path not allowed');
    expect($c['history'])->toHaveCount(0);
});

it('rejects a parent path that is not in the host allow-list with 403', function () {
    $c = makeProxyClient();

    $response = $c['client']->forward('core', false, 'shipments', 'GET', [], '', '');

    expect($response->status)->toBe(403);
    expect(decodeProblem($response->body)['detail'])->toBe('path not allowed');
    expect($c['history'])->toHaveCount(0);
});

it('rejects a traversal-style path with 403', function () {
    $c = makeProxyClient();

    $response = $c['client']->forward('core', false, 'shipments/capabilities/../other', 'GET', [], '', '');

    expect($response->status)->toBe(403);
    expect($c['history'])->toHaveCount(0);
});

it('rejects any path against the empty allow-list of the address host', function () {
    $c = makeProxyClient();

    $response = $c['client']->forward('address', false, 'addresses', 'GET', [], '', '');

    expect($response->status)->toBe(403);
    expect(decodeProblem($response->body)['detail'])->toBe('path not allowed');
    expect($c['history'])->toHaveCount(0);
});

it('rejects a request body larger than 32 KB with 413', function () {
    $c = makeProxyClient();
    $body = str_repeat('a', 32769);

    $response = $c['client']->forward('core', false, 'shipments/capabilities', 'POST', [], $body, '');

    expect($response->status)->toBe(413);
    expect(decodeProblem($response->body)['detail'])->toBe('request body too large');
    expect($c['history'])->toHaveCount(0);
});

it('accepts a request body exactly at the 32 KB cap', function () {
    $c = makeProxyClient('test-api-key', [new GuzzleResponse(200, [], 'ok')]);
    $body = str_repeat('a', 32768);

    $response = $c['client']->forward('core', false, 'shipments/capabilities', 'POST', [], $body, '');

    expect($response->status)->toBe(200);
    expect($c['history'])->toHaveCount(1);
});

it('throws LocalizedException when the API key is empty', function () {
    $c = makeProxyClient('');

    $c['client']->forward('core', false, 'shipments/capabilities', 'GET', [], '', '');
})->throws(LocalizedException::class);

// ---- happy path: outbound request shape -----------------------------------

it('forwards an allowed GET to the production host with injected Authorization and dropped Authorization/Cookie', function () {
    $c = makeProxyClient('secret-key', [new GuzzleResponse(200, [], 'body')]);

    $response = $c['client']->forward(
        'core',
        false,
        'shipments/capabilities',
        'GET',
        [
            'Accept'          => 'application/json',
            'Accept-Language' => 'nl',
            'Authorization'   => 'Bearer should-be-stripped',
            'Cookie'          => 'sessionid=should-be-stripped',
        ],
        '',
        ''
    );

    expect($response->status)->toBe(200);
    expect($c['history'])->toHaveCount(1);

    /** @var GuzzleRequest $sent */
    $sent = $c['history'][0]['request'];
    expect((string) $sent->getUri())->toBe('https://api.myparcel.nl/shipments/capabilities');
    expect($sent->getMethod())->toBe('GET');

    // Inbound Authorization stripped, then replaced with server-side bearer.
    expect($sent->getHeaderLine('Authorization'))->toBe('bearer ' . base64_encode('secret-key'));

    // Cookie dropped.
    expect($sent->hasHeader('Cookie'))->toBeFalse();

    // Other headers passed through.
    expect($sent->getHeaderLine('Accept'))->toBe('application/json');
    expect($sent->getHeaderLine('Accept-Language'))->toBe('nl');
});

it('selects the acceptance URL when acceptance is true', function () {
    $c = makeProxyClient('k', [new GuzzleResponse(200, [], '')]);

    $c['client']->forward('core', true, 'shipments/capabilities', 'GET', [], '', '');

    /** @var GuzzleRequest $sent */
    $sent = $c['history'][0]['request'];
    expect((string) $sent->getUri())->toBe('https://api.acceptance.myparcel.nl/shipments/capabilities');
});

it('appends the query string verbatim when present', function () {
    $c = makeProxyClient('k', [new GuzzleResponse(200, [], '')]);

    $c['client']->forward('core', false, 'shipments/capabilities', 'GET', [], '', 'carrier=postnl&country=NL');

    /** @var GuzzleRequest $sent */
    $sent = $c['history'][0]['request'];
    expect((string) $sent->getUri())->toBe(
        'https://api.myparcel.nl/shipments/capabilities?carrier=postnl&country=NL'
    );
});

it('omits the query string when empty', function () {
    $c = makeProxyClient('k', [new GuzzleResponse(200, [], '')]);

    $c['client']->forward('core', false, 'shipments/capabilities', 'GET', [], '', '');

    /** @var GuzzleRequest $sent */
    $sent = $c['history'][0]['request'];
    expect((string) $sent->getUri())->toBe('https://api.myparcel.nl/shipments/capabilities');
});

it('does not forward a body on GET even when one is supplied', function () {
    $c = makeProxyClient('k', [new GuzzleResponse(200, [], '')]);

    $c['client']->forward('core', false, 'shipments/capabilities', 'GET', [], 'should-not-be-sent', '');

    /** @var GuzzleRequest $sent */
    $sent = $c['history'][0]['request'];
    expect((string) $sent->getBody())->toBe('');
});

it('forwards the body on POST', function () {
    $c = makeProxyClient('k', [new GuzzleResponse(200, [], '')]);

    $c['client']->forward('core', false, 'shipments/capabilities', 'POST', [], '{"a":1}', '');

    /** @var GuzzleRequest $sent */
    $sent = $c['history'][0]['request'];
    expect((string) $sent->getBody())->toBe('{"a":1}');
});

it('configures Guzzle to disable redirects, set a 5s timeout, and tolerate HTTP errors', function () {
    $c = makeProxyClient('k', [new GuzzleResponse(200, [], '')]);

    $c['client']->forward('core', false, 'shipments/capabilities', 'GET', [], '', '');

    $options = $c['history'][0]['options'];
    expect($options['allow_redirects'])->toBeFalse();
    expect($options['http_errors'])->toBeFalse();
    expect($options['timeout'])->toBe(5);
    expect($options['connect_timeout'])->toBe(5);
    expect($options['decode_content'])->toBeTrue();
});

it('uppercases the method before checking the allow-list', function () {
    $c = makeProxyClient('k', [new GuzzleResponse(200, [], '')]);

    $c['client']->forward('core', false, 'shipments/capabilities', 'get', [], '', '');

    /** @var GuzzleRequest $sent */
    $sent = $c['history'][0]['request'];
    expect($sent->getMethod())->toBe('GET');
});

// ---- upstream response handling -------------------------------------------

it('returns the upstream status, body and surviving headers on success', function () {
    $c = makeProxyClient('k', [
        new GuzzleResponse(
            201,
            [
                'Content-Type'      => 'application/json',
                'X-Trace'           => 'abc',
                'Set-Cookie'        => 'session=leak',
                'Transfer-Encoding' => 'chunked',
                'Content-Encoding'  => 'gzip',
                'Connection'        => 'close',
                'Keep-Alive'        => 'timeout=5',
                'Content-Length'    => '10',
            ],
            'response-body'
        ),
    ]);

    $response = $c['client']->forward('core', false, 'shipments/capabilities', 'GET', [], '', '');

    expect($response->status)->toBe(201);
    expect($response->body)->toBe('response-body');
    expect($response->headers)->toHaveKey('Content-Type');
    expect($response->headers)->toHaveKey('X-Trace');

    // Hop-by-hop and content-coding headers must not propagate downstream.
    foreach (['Set-Cookie', 'Transfer-Encoding', 'Content-Encoding', 'Connection', 'Keep-Alive', 'Content-Length'] as $dropped) {
        expect(array_change_key_case($response->headers, CASE_LOWER))
            ->not->toHaveKey(strtolower($dropped));
    }
});

it('returns 502 application/problem+json when Guzzle throws', function () {
    $c = makeProxyClient('k', [
        new ConnectException('upstream down', new GuzzleRequest('GET', 'https://api.myparcel.nl/')),
    ]);

    $response = $c['client']->forward('core', false, 'shipments/capabilities', 'GET', [], '', '');

    expect($response->status)->toBe(502);
    expect($response->headers['Content-Type'])->toBe(ProblemDetails::CONTENT_TYPE);
    expect(decodeProblem($response->body)['status'])->toBe(502);
    expect(decodeProblem($response->body)['detail'])->toBe('upstream unreachable');
});
