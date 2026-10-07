<?php

declare(strict_types=1);

use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
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
/**
 * @param array<int, string|null> $keysByStore store id => its api key; also the store views the store manager lists
 * @param int|null                $keyLookups  the store key lookups to expect; null for any number
 */
function storedAccountForStores(array $keysByStore, ?int $keyLookups = null): StoredAccount
{
    $provider = Mockery::mock(ShipmentApiProvider::class);
    $lookup   = $provider->shouldReceive('apiKeyForStoreOrNull')
        ->andReturnUsing(static fn(int $storeId) => $keysByStore[$storeId]);

    if (null !== $keyLookups) {
        $lookup->times($keyLookups);
    }

    $stores = array_map(static fn(int $id) => new DataObject(['id' => $id]), array_keys($keysByStore));

    return new StoredAccount($provider, Mockery::mock(StoreManagerInterface::class, ['getStores' => $stores]));
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

    expect(storedAccountForStores([1 => 'live-key'])->propositionForStore(1)->getId())->toBe(3);
});

it('answers null for a store with no api key', function () {
    stubAccountSettingsReader([]);

    expect(storedAccountForStores([1 => null])->propositionForStore(1))->toBeNull();
});

it('answers null without a lookup when there is no store', function () {
    expect(storedAccountForStores([1 => 'live-key'], 0)->propositionForStore(null))->toBeNull();
});

it('answers null rather than throwing when the row cannot be read', function () {
    $logger = stubAccountSettingsReader([], unreadableSettings());

    expect(storedAccountForStores([1 => 'live-key'])->propositionForStore(1))->toBeNull();

    $logger->shouldHaveReceived('alert')->once();
});

it('answers null and warns once when the account names a proposition the module does not list', function () {
    $logger = stubAccountSettingsReader([
        settingsPathFor('live-key') => accountSettingsRow([], ['account' => ['id' => 7, 'proposition_id' => 99]]),
    ]);
    $account = storedAccountForStores([1 => 'live-key'], 2);

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

    $account = storedAccountForStores([1 => 'be-key', 2 => null]);

    expect($account->homeCountryForStore(1))->toBe('BE')
        ->and($account->homeCountryForStore(2))->toBe('NL');
});

it('reads the proposition and home country of an api key without asking for a store', function () {
    stubAccountSettingsReader([
        settingsPathFor('be-key') => accountSettingsRow([], ['account' => ['id' => 7, 'proposition_id' => 3]]),
    ]);
    $account = storedAccountForStores([], 0);

    expect($account->propositionForApiKey('be-key')->getId())->toBe(3)
        ->and($account->homeCountryForApiKey('be-key'))->toBe('BE')
        ->and($account->homeCountryForApiKey('unknown-key'))->toBe('NL');
});

it('reads an account once, so stores that share its key cost one alert per page and not one per row', function () {
    $logger  = stubAccountSettingsReader([], unreadableSettings());
    $account = storedAccountForStores([1 => 'live-key', 2 => 'live-key'], 2);

    $account->propositionForStore(1);
    $account->propositionForStore(2);

    $logger->shouldHaveReceived('alert')->once();
});

it('exports entire orders for order v1 only: not for order v2, and not without features', function () {
    stubAccountSettingsReader([
        settingsPathFor('order-key') => accountSettingsRow([], [
            'account'  => ['id' => 7, 'proposition_id' => 1],
            'features' => ['LEGACY_ORDER_MANAGEMENT'],
        ]),
        settingsPathFor('shipment-key') => accountSettingsRow([], ['account' => ['id' => 8, 'proposition_id' => 1]]),
        settingsPathFor('v2-key')       => accountSettingsRow([], [
            'account'  => ['id' => 9, 'proposition_id' => 1, 'general_settings' => ['order_mode' => true]],
            'features' => ['ORDER_MANAGEMENT'],
        ]),
    ]);
    $account = storedAccountForStores([1 => 'order-key', 2 => 'shipment-key', 3 => null, 4 => 'v2-key']);

    expect($account->hasOrderV1ForStore(1))->toBeTrue()
        ->and($account->hasOrderV1ForStore(2))->toBeFalse()
        ->and($account->hasOrderV1ForStore(3))->toBeFalse()
        ->and($account->hasOrderV1ForStore(null))->toBeFalse()
        ->and($account->hasOrderV1ForStore(4))->toBeFalse();
});

it('lists whether every store with an api key has order v1, and only those', function () {
    stubAccountSettingsReader([
        settingsPathFor('order-key') => accountSettingsRow([], [
            'account'  => ['id' => 7, 'proposition_id' => 1],
            'features' => ['LEGACY_ORDER_MANAGEMENT'],
        ]),
        settingsPathFor('shipment-key') => accountSettingsRow([], ['account' => ['id' => 8, 'proposition_id' => 1]]),
    ]);

    expect(storedAccountForStores([1 => 'order-key', 2 => 'shipment-key', 3 => null])->orderV1ByStore())
        ->toBe([1 => true, 2 => false]);
});
