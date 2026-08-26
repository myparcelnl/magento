<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Cache\Type\Capabilities as CapabilitiesCache;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Client as CapabilitiesClient;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;

const CAPABILITIES_TEST_API_KEY = 'test-api-key-do-not-log';

/**
 * One `results` entry, shaped like the V2 response: camelCase wire keys, `carrier` a bare enum
 * string, option values carrying their own properties.
 */
function capabilityResult(array $overrides = []): array
{
    return array_replace([
        'carrier'       => 'POSTNL',
        'contract'      => ['id' => 1],
        'packageTypes'  => ['PACKAGE', 'MAILBOX', 'DIGITAL_STAMP', 'SMALL_PACKAGE'],
        'deliveryTypes' => ['STANDARD_DELIVERY', 'MORNING_DELIVERY', 'EVENING_DELIVERY', 'PICKUP_DELIVERY'],
        'options'       => [
            'requiresSignature'     => ['isRequired' => false, 'isSelectedByDefault' => false],
            'recipientOnlyDelivery' => ['isRequired' => false, 'isSelectedByDefault' => false],
            'insurance'             => [
                'isRequired' => false,
                'min'        => ['amount' => 0, 'currency' => 'EUR'],
                'max'        => ['amount' => 500000, 'currency' => 'EUR'],
                'default'    => ['amount' => 10000, 'currency' => 'EUR'],
            ],
        ],
        'collo'         => ['max' => 10],
    ], $overrides);
}

/** The whole response envelope, JSON-encoded, ready for a MockHandler Response body. */
function capabilitiesBody(array $results): string
{
    return (string) json_encode(['results' => $results]);
}

/**
 * @param  \GuzzleHttp\Psr7\Response[]|\Throwable[] $responses
 * @return array{client: CapabilitiesClient, history: array, handler: \GuzzleHttp\Handler\MockHandler}
 */
function makeCapabilitiesClient(array $responses = [], ?string $host = null): array
{
    $http = makeGuzzleWithHistory($responses);

    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getVersion')->andReturn('5.9.0')->byDefault();

    return [
        'client'  => new CapabilitiesClient($http['client'], $config, null, $host),
        'history' => &$http['history'],
        'handler' => $http['handler'],
    ];
}

/**
 * A Repository over an in-memory cache double, so cache hits and misses are observable.
 *
 * @param  \GuzzleHttp\Psr7\Response[]|\Throwable[] $responses
 * @return array{repository: CapabilitiesRepository, history: array, store: object, config: Config}
 */
function makeCapabilitiesRepository(array $responses = [], ?string $apiKey = CAPABILITIES_TEST_API_KEY): array
{
    $client = makeCapabilitiesClient($responses);

    $store = new class {
        public array $entries = [];
        public array $savedTags = [];
        public int $cleans = 0;
    };

    $cache = Mockery::mock(CapabilitiesCache::class);
    $cache->shouldReceive('load')->andReturnUsing(static function (string $id) use ($store) {
        return $store->entries[$id] ?? false;
    });
    $cache->shouldReceive('save')->andReturnUsing(
        static function ($data, string $id, array $tags = [], $lifeTime = null) use ($store): bool {
            $store->entries[$id]   = (string) $data;
            $store->savedTags[$id] = ['tags' => $tags, 'lifeTime' => $lifeTime];

            return true;
        }
    );

    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getGeneralConfig')->with('api/key', Mockery::any())->andReturn($apiKey)->byDefault();

    return [
        'repository' => new CapabilitiesRepository($client['client'], $cache, $config, new Fingerprint()),
        'history'    => &$client['history'],
        'store'      => $store,
        'config'     => $config,
    ];
}
