<?php

declare(strict_types=1);

use GuzzleHttp\Client as RealGuzzleClient;
use MyParcelNL\Magento\Model\Cache\Type\Capabilities as CapabilitiesCache;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Client as CapabilitiesClient;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Model\Shipment\Capabilities\InsuranceRange;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Proxy\ProxyConfig;
use PHPUnit\Framework\Assert;
use MyParcelNL\Magento\Service\Hash\Fingerprint;

const CAPABILITIES_TEST_API_KEY = 'test-api-key-do-not-log';

/**
 * The `options` object both endpoints return, in cents. Shared so a change to the insurance shape
 * lands in one place.
 */
function capabilityOptions(array $overrides = []): array
{
    return array_replace([
        'requiresSignature'     => ['isRequired' => false, 'isSelectedByDefault' => false],
        'recipientOnlyDelivery' => ['isRequired' => false, 'isSelectedByDefault' => false],
        'insurance'             => [
            'isRequired' => false,
            'min'        => ['amount' => 0, 'currency' => 'EUR'],
            'max'        => ['amount' => 500000, 'currency' => 'EUR'],
            'default'    => ['amount' => 10000, 'currency' => 'EUR'],
        ],
    ], $overrides);
}

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
        'options'       => capabilityOptions(),
        'collo'         => ['max' => 10],
    ], $overrides);
}

/**
 * One `items` entry from the contract-definitions endpoint. Same wire keys as a `results` entry
 * minus `contract` and `physicalProperties`, which is why one parser reads both.
 */
function contractDefinitionItem(array $overrides = []): array
{
    return array_replace([
        'carrier'          => 'POSTNL',
        'packageTypes'     => ['PACKAGE', 'MAILBOX', 'DIGITAL_STAMP', 'SMALL_PACKAGE'],
        'deliveryTypes'    => ['STANDARD_DELIVERY', 'PICKUP_DELIVERY'],
        'transactionTypes' => ['DELIVERY'],
        'options'          => capabilityOptions(),
        'collo'            => ['max' => 10],
    ], $overrides);
}

/** The whole response envelope, JSON-encoded, ready for a MockHandler Response body. */
function capabilitiesBody(array $results): string
{
    return (string) json_encode(['results' => $results]);
}

/** The contract-definitions envelope. `items`, not `results` — the one shape difference. */
function contractDefinitionsBody(array $items): string
{
    return (string) json_encode(['items' => $items]);
}

/** The stored account settings row a ContractDefinitions reader decodes. */
function accountSettingsRow(array $items): string
{
    return (string) json_encode([
        'shop'                 => ['id' => 42, 'name' => 'Test Shop'],
        'account'              => ['id' => 7],
        'contract_definitions' => $items,
    ]);
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

/**
 * The captured acceptance response InsuranceShapeConformanceTest reads. Written by that file's live
 * case, which is the only supported way to refresh it.
 */
function acceptanceCapabilitiesFixturePath(): string
{
    return __DIR__ . '/../Fixtures/capabilities-acceptance-v2.json';
}

/**
 * A real response reduced to the five keys CarrierCapability::fromResult() reads. An allow-list, so a
 * key naming the account it came from cannot reach the committed fixture.
 */
function scrubCapabilitiesResults(array $results): array
{
    return array_values(array_map(
        static fn(array $result): array => array_intersect_key(
            $result,
            array_flip(['carrier', 'packageTypes', 'deliveryTypes', 'collo', 'options'])
        ),
        $results
    ));
}

/**
 * A capabilities client that really calls acceptance. Only InsuranceShapeConformanceTest's live case
 * builds one; every other capabilities test goes through makeCapabilitiesClient()'s MockHandler.
 */
function makeAcceptanceCapabilitiesClient(): CapabilitiesClient
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getVersion')->andReturn('5.9.0')->byDefault();

    return new CapabilitiesClient(
        new RealGuzzleClient(),
        $config,
        null,
        ProxyConfig::HOSTS[ProxyConfig::HOST_CORE][ProxyConfig::KEY_ACCEPTANCE_URL]
    );
}

/**
 * Asserts that every insurance option in a capabilities response carries the flat Money properties
 * InsuranceRange reads, and that InsuranceRange still parses them. Shared by the fixture case and the
 * live case so the two cannot drift.
 *
 * The deprecated `insuredAmount` wrapper may ride along beside the flat properties; InsuranceRange
 * ignores it, so its presence is not a failure. A missing flat property is. Every message names the
 * carrier and the keys the option actually carried, because that is the whole diagnostic when the
 * shape moves.
 */
function assertFlatInsuranceShape(array $results): void
{
    expect($results)->not->toBeEmpty();

    $seen = 0;

    foreach ($results as $result) {
        $insurance = $result['options']['insurance'] ?? null;

        if (! is_array($insurance)) {
            continue;
        }

        $seen++;

        $where = sprintf(
            '%s insurance option carries: %s',
            (string) ($result['carrier'] ?? 'unknown carrier'),
            implode(', ', array_keys($insurance))
        );

        Assert::assertTrue(
            isset($insurance['max']['amount']) && is_numeric($insurance['max']['amount']),
            "No flat max Money property. $where"
        );

        foreach (['min', 'default'] as $key) {
            if (array_key_exists($key, $insurance)) {
                Assert::assertTrue(
                    isset($insurance[$key]['amount']) && is_numeric($insurance[$key]['amount']),
                    "Flat $key is not a Money object. $where"
                );
            }
        }

        // The assertion that matters: the wire shape and the parser still agree.
        Assert::assertNotNull(
            InsuranceRange::fromOptionValue($insurance),
            "InsuranceRange read no range from this shape. $where"
        );
    }

    expect($seen)->toBeGreaterThan(0);
}
