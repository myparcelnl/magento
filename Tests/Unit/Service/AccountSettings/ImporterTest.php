<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Client;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Sdk\Model\Account\Account;
use MyParcelNL\Sdk\Model\Account\Shop;
use MyParcelNL\Sdk\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * importFor() itself has no seam: it instantiates the SDK account web service directly. What is
 * reachable is hasSettingsFor(), and the two halves the contract-definitions work owns.
 *
 * @param array<string, string> $rowsByPath
 */
function importerFor(array $rowsByPath, ?Client $client = null): Importer
{
    $scopeConfig = Mockery::mock(ScopeConfigInterface::class);
    $scopeConfig->shouldReceive('getValue')->andReturnUsing(
        static fn (string $path) => $rowsByPath[$path] ?? null
    );

    return new Importer(
        Mockery::spy(WriterInterface::class),
        $scopeConfig,
        new Fingerprint(),
        Mockery::spy(LoggerInterface::class),
        $client ?? Mockery::spy(Client::class)
    );
}

/** A client answering one item per carrier it is asked about, and nothing for the rest. */
function importerClientAnswering(array $itemsByV2Carrier): Client
{
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('sendContractDefinitions')->andReturnUsing(
        static function (string $apiKey, string $v2Carrier) use ($itemsByV2Carrier): array {
            if (! array_key_exists($v2Carrier, $itemsByV2Carrier)) {
                throw new RuntimeException('contract definitions responded 404');
            }

            return $itemsByV2Carrier[$v2Carrier];
        }
    );

    return $client;
}

it('reports settings present when a row exists for the key', function () {
    $importer = importerFor([settingsPathFor('live-key') => '{"shop":1}']);

    expect($importer->hasSettingsFor('live-key'))->toBeTrue();
});

it('reports settings absent when no row exists at all', function () {
    expect(importerFor([])->hasSettingsFor('live-key'))->toBeFalse();
});

it('does not mistake another key\'s row for its own', function () {
    $importer = importerFor([settingsPathFor('other-key') => '{"shop":9}']);

    expect($importer->hasSettingsFor('live-key'))->toBeFalse();
});

it('treats an empty stored value as absent', function () {
    $importer = importerFor([settingsPathFor('live-key') => '']);

    expect($importer->hasSettingsFor('live-key'))->toBeFalse();
});

it('asks for contract definitions once per configured carrier', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('notice')->zeroOrMoreTimes();
    // Every carrier answering nothing leaves the admin screens unbounded, which only the log says.
    $logger->shouldReceive('warning')->once();

    $asked  = [];
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('sendContractDefinitions')->andReturnUsing(
        static function (string $apiKey, string $v2Carrier) use (&$asked): array {
            $asked[] = $v2Carrier;

            return [];
        }
    );

    invokePrivateMethod(importerFor([], $client), 'fetchContractDefinitions', ['live-key']);

    expect($asked)->toBe(array_values(Carrier::V2_NAMES_MAP));
});

it('flattens every carrier answer into one list', function () {
    mockLoggerFacade()->shouldReceive('notice')->zeroOrMoreTimes();

    $client = importerClientAnswering([
        'POSTNL'      => [contractDefinitionItem(['carrier' => 'POSTNL'])],
        'DHL_FOR_YOU' => [contractDefinitionItem(['carrier' => 'DHL_FOR_YOU'])],
    ]);

    $definitions = invokePrivateMethod(importerFor([], $client), 'fetchContractDefinitions', ['live-key']);

    expect($definitions)->toHaveCount(2)
        ->and(array_column($definitions, 'carrier'))->toBe(['POSTNL', 'DHL_FOR_YOU']);
});

it('keeps the carriers it could reach when one has no contract', function () {
    mockLoggerFacade()->shouldReceive('notice')->atLeast()->once();

    $client = importerClientAnswering(['POSTNL' => [contractDefinitionItem()]]);

    $definitions = invokePrivateMethod(importerFor([], $client), 'fetchContractDefinitions', ['live-key']);

    expect($definitions)->toHaveCount(1);
});

it('keeps insurance bounds verbatim on the way into storage', function () {
    mockLoggerFacade()->shouldReceive('notice')->zeroOrMoreTimes();

    $client = importerClientAnswering(['POSTNL' => [contractDefinitionItem()]]);

    $definitions = invokePrivateMethod(importerFor([], $client), 'fetchContractDefinitions', ['live-key']);

    expect($definitions[0]['options']['insurance']['max']['amount'])->toBe(500000);
});

it('stores shop, account and contract definitions and nothing else', function () {
    $settings = new Collection([
        'shop'                 => new Shop(['id' => 42, 'name' => 'Test Shop']),
        'account'              => new Account([
            'id'               => 7,
            'platform_id'      => 1,
            'shops'            => [['id' => 42, 'name' => 'Test Shop']],
            'general_settings' => [],
        ]),
        'contract_definitions' => [contractDefinitionItem()],
    ]);

    $stored = invokePrivateMethod(importerFor([]), 'createArray', [$settings]);

    expect(array_keys($stored))->toBe(['shop', 'account', 'contract_definitions'])
        ->and($stored['shop'])->toBe(['id' => 42, 'name' => 'Test Shop'])
        ->and($stored['account']['id'])->toBe(7)
        ->and($stored['contract_definitions'][0]['carrier'])->toBe('POSTNL');
});
