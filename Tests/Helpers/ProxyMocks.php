<?php

declare(strict_types=1);

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Mock a single Magento store that returns the given web base URL.
 */
function mockStoreWithBaseUrl(string $baseUrl): StoreInterface
{
    $store = Mockery::mock(StoreInterface::class);
    $store->shouldReceive('getBaseUrl')
        ->with(UrlInterface::URL_TYPE_WEB, true)
        ->andReturn($baseUrl);
    return $store;
}

/**
 * Mock StoreManagerInterface returning stores for the given web base URLs.
 *
 * @param string[] $baseUrls
 */
function mockStoreManagerWithBaseUrls(array $baseUrls): StoreManagerInterface
{
    $stores = [];
    foreach ($baseUrls as $baseUrl) {
        $stores[] = mockStoreWithBaseUrl($baseUrl);
    }
    $manager = Mockery::mock(StoreManagerInterface::class);
    $manager->shouldReceive('getStores')->andReturn($stores);
    return $manager;
}

/**
 * Mock a proxy RequestInterface with a method and case-insensitive header map.
 *
 * @param array<string,string> $headers
 */
function mockProxyRequest(string $method, array $headers = []): RequestInterface
{
    $lowercased = [];
    foreach ($headers as $name => $value) {
        $lowercased[strtolower($name)] = $value;
    }

    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getMethod')->andReturn($method);
    $request->shouldReceive('getHeader')->andReturnUsing(
        static function (string $name) use ($lowercased) {
            return $lowercased[strtolower($name)] ?? false;
        }
    );
    return $request;
}

/**
 * Mock a Raw result that records every setHeader/setHttpResponseCode/setContents call.
 *
 * Returns ['raw' => Raw mock, 'calls' => array reference] — assert against $captured['calls'].
 * Each call is ['method' => string, 'args' => array].
 *
 * @return array{raw: Raw, calls: array<int, array{method: string, args: array<int, mixed>}>}
 */
function captureRawResult(): array
{
    $raw   = Mockery::mock(Raw::class);
    $calls = [];

    $recorder = static function (string $method) use (&$calls, $raw) {
        return static function (...$args) use ($method, &$calls, $raw) {
            $calls[] = ['method' => $method, 'args' => $args];
            return $raw;
        };
    };

    $raw->shouldReceive('setHttpResponseCode')->andReturnUsing($recorder('setHttpResponseCode'));
    $raw->shouldReceive('setHeader')->andReturnUsing($recorder('setHeader'));
    $raw->shouldReceive('setContents')->andReturnUsing($recorder('setContents'));

    return ['raw' => $raw, 'calls' => &$calls];
}

/**
 * Mock RawFactory::create() to return the given Raw instance.
 */
function mockRawFactoryReturning(Raw $raw): RawFactory
{
    $factory = Mockery::mock(RawFactory::class);
    $factory->shouldReceive('create')->andReturn($raw);
    return $factory;
}

/**
 * Extract setHeader calls from a captureRawResult() bag as a [name => value] map.
 * Last-write-wins per name (so replace=true semantics are honoured here too).
 *
 * @param array{calls: array<int, array{method: string, args: array<int, mixed>}>} $bag
 * @return array<string,string>
 */
function rawHeadersAsMap(array $bag): array
{
    $map = [];
    foreach ($bag['calls'] as $call) {
        if ($call['method'] === 'setHeader') {
            $map[(string) $call['args'][0]] = (string) $call['args'][1];
        }
    }
    return $map;
}

/**
 * Find calls to a specific method on a captureRawResult() bag.
 *
 * @param array{calls: array<int, array{method: string, args: array<int, mixed>}>} $bag
 * @return array<int, array<int, mixed>>
 */
function rawCallsTo(array $bag, string $method): array
{
    $out = [];
    foreach ($bag['calls'] as $call) {
        if ($call['method'] === $method) {
            $out[] = $call['args'];
        }
    }
    return $out;
}
