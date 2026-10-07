<?php

declare(strict_types=1);

use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Service\Capabilities\Client;
use MyParcelNL\Magento\Service\AccountSettings\AccountFeatures;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Model\Account\Account;
use MyParcelNL\Sdk\Model\Account\Shop;
use MyParcelNL\Sdk\Support\Collection;
use Psr\Log\LoggerInterface;

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

it('reports settings present when a row with contract definitions exists for the key', function () {
    expect(importerFor(contractRow())->hasSettingsFor('live-key'))->toBeTrue();
});

it('treats a row with an empty list as absent, as the screens read it as unknown', function () {
    $importer = importerFor([settingsPathFor('live-key') => '{"shop":1,"contract_definitions":[]}']);

    expect($importer->hasSettingsFor('live-key'))->toBeFalse();
});

it('treats a row without contract definitions as absent, so the next save imports it again', function () {
    $importer = importerFor([settingsPathFor('live-key') => '{"shop":1}']);

    expect($importer->hasSettingsFor('live-key'))->toBeFalse();
});

it('reports settings absent when no row exists at all', function () {
    expect(importerFor([])->hasSettingsFor('live-key'))->toBeFalse();
});

it('does not mistake another key\'s row for its own', function () {
    // A usable row: an empty list reads as absent under any key, so it would hide a wrong lookup.
    $importer = importerFor([settingsPathFor('other-key') => accountSettingsRow([contractDefinitionItem()])]);

    expect($importer->hasSettingsFor('live-key'))->toBeFalse();
});

it('treats an empty stored value as absent', function () {
    $importer = importerFor([settingsPathFor('live-key') => '']);

    expect($importer->hasSettingsFor('live-key'))->toBeFalse();
});

it('asks for contract definitions exactly once, with no carrier filter', function () {
    $logger = Mockery::mock(LoggerInterface::class);
    // An answer carrying nothing leaves the admin screens unbounded, which only the log says.
    $logger->shouldReceive('warning')->once();

    $client = Mockery::mock(Client::class);
    // One call without a carrier filter: the response names each item's carrier.
    $client->shouldReceive('sendContractDefinitions')->once()->with('live-key')->andReturn([]);

    invokePrivateMethod(importerFor([], $client, null, $logger), 'fetchContractDefinitions', ['live-key']);
});

it('keeps a mixed-carrier answer as one list, in the order it arrived', function () {
    $client = importerClientAnswering([
        contractDefinitionItem(['carrier' => 'POSTNL']),
        contractDefinitionItem(['carrier' => 'DHL_FOR_YOU']),
    ]);

    $definitions = invokePrivateMethod(importerFor([], $client), 'fetchContractDefinitions', ['live-key']);

    expect($definitions)->toHaveCount(2)
        ->and(array_column($definitions, 'carrier'))->toBe(['POSTNL', 'DHL_FOR_YOU']);
});

it('answers unknown when the call is refused, rather than failing the import', function () {
    // One line per failure, with the exception.
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->with(Mockery::type('string'), Mockery::on(fn ($context) => [] !== $context))->once();

    $definitions = invokePrivateMethod(
        importerFor([], importerClientRefusing(), null, $logger),
        'fetchContractDefinitions',
        ['live-key']
    );

    expect($definitions)->toBeNull();
});

it('keeps the stored list when the call is refused', function () {
    $definitions = invokePrivateMethod(
        importerFor(contractRow(), importerClientRefusing()),
        'fetchContractDefinitions',
        ['live-key']
    );

    expect($definitions[0]['carrier'])->toBe('POSTNL');
});

it('keeps the stored list when the account answers none', function (array $answer) {
    $definitions = invokePrivateMethod(
        importerFor(contractRow(), importerClientAnswering($answer)),
        'fetchContractDefinitions',
        ['live-key']
    );

    expect($definitions[0]['carrier'])->toBe('POSTNL');
})->with([
    'an empty list'                  => [[]],
    'only entries that are no array' => [['x', 1]],
]);

it('leaves contract definitions out when none are known, so the next save imports them again', function () {
    $settings = new Collection([
        'shop'                 => new Shop(['id' => 42, 'name' => 'Test Shop']),
        'account'              => new Account(['id' => 7, 'platform_id' => 1, 'shops' => [], 'general_settings' => []]),
        'contract_definitions' => null,
    ]);

    $row = invokePrivateMethod(importerFor([]), 'createArray', [$settings]);

    expect($row)->not->toHaveKey('contract_definitions');
});

it('keeps insurance bounds verbatim on the way into storage', function () {
    $client = importerClientAnswering([contractDefinitionItem()]);

    $definitions = invokePrivateMethod(importerFor([], $client), 'fetchContractDefinitions', ['live-key']);

    expect($definitions[0]['options']['insurance']['max']['amount'])->toBe(500000);
});

it('fetches the account features from whoami', function () {
    $features = Mockery::mock(AccountFeatures::class);
    $features->shouldReceive('forApiKey')->once()->with('live-key')->andReturn(['LEGACY_ORDER_MANAGEMENT']);

    expect(invokePrivateMethod(importerFor([], null, $features), 'fetchFeatures', ['live-key']))
        ->toBe(['LEGACY_ORDER_MANAGEMENT']);
});

it('answers no features rather than failing the import when whoami fails', function () {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once();
    $features = Mockery::mock(AccountFeatures::class);
    $features->shouldReceive('forApiKey')->andThrow(new RuntimeException('iam unavailable'));

    expect(invokePrivateMethod(importerFor([], null, $features, $logger), 'fetchFeatures', ['live-key']))->toBeNull();
});

it('stores shop, account and contract definitions and nothing else', function () {
    $settings = new Collection([
        'shop'                 => new Shop(['id' => 42, 'name' => 'Test Shop']),
        'account'              => new Account([
            'id'               => 7,
            'proposition_id'   => 1,
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

it('stores the features beside the account when whoami answered', function () {
    $settings = new Collection([
        'shop'                 => new Shop(['id' => 42, 'name' => 'Test Shop']),
        'account'              => new Account([
            'id'               => 7,
            'proposition_id'   => 1,
            'shops'            => [['id' => 42, 'name' => 'Test Shop']],
            'general_settings' => [],
        ]),
        'contract_definitions' => [],
        'features'             => ['ORDER_MANAGEMENT'],
    ]);

    expect(invokePrivateMethod(importerFor([]), 'createArray', [$settings])['features'])->toBe(['ORDER_MANAGEMENT']);
});
