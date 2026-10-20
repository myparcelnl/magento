<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/**
 * A Guzzle client backed by a MockHandler, with a history array capturing every outgoing request so
 * tests can assert URL, method, headers and body.
 *
 * The history is returned by reference: read it after the call, not before.
 *
 * @param  \GuzzleHttp\Psr7\Response[]|\Throwable[] $responses
 * @return array{client: GuzzleClient, history: array, handler: MockHandler}
 */
function makeGuzzleWithHistory(array $responses = []): array
{
    $history     = [];
    $mockHandler = new MockHandler($responses);
    $stack       = HandlerStack::create($mockHandler);
    $stack->push(Middleware::history($history));

    return [
        'client'  => new GuzzleClient(['handler' => $stack]),
        'history' => &$history,
        'handler' => $mockHandler,
    ];
}

/**
 * A logger double that accepts every PSR-3 level without expectations, for tests where logging is
 * incidental rather than the thing under test.
 */
function makePermissiveLogger(): \Psr\Log\LoggerInterface
{
    $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
        $logger->shouldReceive($level)->byDefault();
    }

    return $logger;
}
