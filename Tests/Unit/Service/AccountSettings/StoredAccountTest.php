<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;

/**
 * The proposition of the account behind an api key, or behind a store's api key.
 *
 * Read while the order grid renders, so a keyless store and a row that cannot be read at all must
 * both answer null instead of throwing, and one account must cost one read.
 *
 * stubAccountSettingsReader() and settingsPathFor() live in Tests/Helpers/AccountSettingsHelpers.php,
 * accountSettingsRow() in Tests/Helpers/CapabilitiesFixtures.php.
 */
function makeStoredAccount(?string $apiKey, int $keyLookups = 1): StoredAccount
{
    $provider = Mockery::mock(ShipmentApiProvider::class);
    $provider->shouldReceive('apiKeyForStoreOrNull')->times($keyLookups)->andReturn($apiKey);

    return new StoredAccount($provider);
}

/** A read that fails, standing for anything between the config row and the SDK throwing. */
function unreadableSettings(): callable
{
    return static function (): void {
        throw new RuntimeException('config unavailable');
    };
}

it('reads the proposition behind a store its api key', function () {
    stubAccountSettingsReader([
        settingsPathFor('live-key') => accountSettingsRow([], ['account' => ['id' => 7, 'proposition_id' => 3]]),
    ]);

    expect(makeStoredAccount('live-key')->propositionForStore(1)->getId())->toBe(3);
});

it('answers null for a store with no api key', function () {
    stubAccountSettingsReader([]);

    expect(makeStoredAccount(null)->propositionForStore(1))->toBeNull();
});

it('answers null without a lookup when there is no store', function () {
    expect(makeStoredAccount('live-key', 0)->propositionForStore(null))->toBeNull();
});

it('answers null rather than throwing when the row cannot be read', function () {
    $logger = stubAccountSettingsReader([], unreadableSettings());

    expect(makeStoredAccount('live-key')->propositionForStore(1))->toBeNull();

    $logger->shouldHaveReceived('alert')->once();
});

it('answers null and warns once when the account names a proposition the module does not list', function () {
    $logger = stubAccountSettingsReader([
        settingsPathFor('live-key') => accountSettingsRow([], ['account' => ['id' => 7, 'proposition_id' => 99]]),
    ]);
    $account = makeStoredAccount('live-key', 2);

    expect($account->propositionForStore(1))->toBeNull()
        ->and($account->propositionForStore(1))->toBeNull();

    $logger->shouldHaveReceived('warning')->once()->with(Mockery::on(
        static fn ($message): bool => is_string($message) && str_contains($message, 'proposition 99')
    ));
});

it('gives the home country of the store its proposition, or the default one', function () {
    stubAccountSettingsReader([
        settingsPathFor('be-key') => accountSettingsRow([], ['account' => ['id' => 7, 'proposition_id' => 3]]),
    ]);

    expect(makeStoredAccount('be-key')->homeCountryForStore(1))->toBe('BE')
        ->and(makeStoredAccount(null)->homeCountryForStore(1))->toBe('NL');
});

it('reads the proposition and home country of an api key without asking for a store', function () {
    stubAccountSettingsReader([
        settingsPathFor('be-key') => accountSettingsRow([], ['account' => ['id' => 7, 'proposition_id' => 3]]),
    ]);
    $account = makeStoredAccount('unused', 0);

    expect($account->propositionForApiKey('be-key')->getId())->toBe(3)
        ->and($account->homeCountryForApiKey('be-key'))->toBe('BE')
        ->and($account->homeCountryForApiKey('unknown-key'))->toBe('NL');
});

it('reads an account once, so stores that share its key cost one alert per page and not one per row', function () {
    $logger  = stubAccountSettingsReader([], unreadableSettings());
    $account = makeStoredAccount('live-key', 2);

    $account->propositionForStore(1);
    $account->propositionForStore(2);

    $logger->shouldHaveReceived('alert')->once();
});
