<?php

declare(strict_types=1);

use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Client;
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
    $scopeConfig = mockScopeConfig($rowsByPath);

    return new Importer(
        Mockery::spy(WriterInterface::class),
        $scopeConfig,
        new Fingerprint(),
        Mockery::spy(LoggerInterface::class),
        $client ?? Mockery::spy(Client::class),
        createUserAgent()
    );
}

/**
 * A client answering one unfiltered call with the given items, whatever carriers they name.
 *
 * @param array<int, array<string, mixed>> $items
 */
function importerClientAnswering(array $items): Client
{
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('sendContractDefinitions')->andReturn($items);

    return $client;
}

/** A client that cannot answer at all, so the import has to degrade. */
function importerClientRefusing(): Client
{
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('sendContractDefinitions')
           ->andThrow(new RuntimeException('contract definitions responded 500'));

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

it('asks for contract definitions exactly once, with no carrier filter', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('notice')->zeroOrMoreTimes();
    // An answer carrying nothing leaves the admin screens unbounded, which only the log says.
    $logger->shouldReceive('warning')->once();

    $client = Mockery::mock(Client::class);
    // One argument, one call: the filter cost a request per carrier and the response names each
    // item's carrier anyway.
    $client->shouldReceive('sendContractDefinitions')->once()->with('live-key')->andReturn([]);

    invokePrivateMethod(importerFor([], $client), 'fetchContractDefinitions', ['live-key']);
});

it('keeps a mixed-carrier answer as one list, in the order it arrived', function () {
    mockLoggerFacade()->shouldReceive('notice')->zeroOrMoreTimes();

    $client = importerClientAnswering([
        contractDefinitionItem(['carrier' => 'POSTNL']),
        contractDefinitionItem(['carrier' => 'DHL_FOR_YOU']),
    ]);

    $definitions = invokePrivateMethod(importerFor([], $client), 'fetchContractDefinitions', ['live-key']);

    expect($definitions)->toHaveCount(2)
        ->and(array_column($definitions, 'carrier'))->toBe(['POSTNL', 'DHL_FOR_YOU']);
});

it('degrades to nothing when the call is refused, rather than failing the import', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('notice')->atLeast()->once();
    $logger->shouldReceive('warning')->once();

    $definitions = invokePrivateMethod(
        importerFor([], importerClientRefusing()),
        'fetchContractDefinitions',
        ['live-key']
    );

    expect($definitions)->toBe([]);
});

it('keeps insurance bounds verbatim on the way into storage', function () {
    mockLoggerFacade()->shouldReceive('notice')->zeroOrMoreTimes();

    $client = importerClientAnswering([contractDefinitionItem()]);

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
