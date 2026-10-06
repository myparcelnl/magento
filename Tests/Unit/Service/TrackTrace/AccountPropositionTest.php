<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\TrackTrace\AccountProposition;

/**
 * The proposition id the fallback track & trace host is picked by.
 *
 * Read once per store while the order grid renders, so a keyless store and a row that cannot be read
 * at all must both answer null instead of throwing, and one store must cost one read.
 *
 * stubAccountSettingsReader() and settingsPathFor() live in Tests/Helpers/AccountSettingsHelpers.php,
 * accountSettingsRow() in Tests/Helpers/CapabilitiesFixtures.php.
 */
function makeAccountProposition(?string $apiKey, int $keyLookups = 1): AccountProposition
{
    $provider = Mockery::mock(ShipmentApiProvider::class);
    $provider->shouldReceive('apiKeyForStoreOrNull')->times($keyLookups)->andReturn($apiKey);

    return new AccountProposition($provider);
}

/** A read that fails, standing for anything between the config row and the SDK throwing. */
function unreadableSettings(): callable
{
    return static function (): void {
        throw new RuntimeException('config unavailable');
    };
}

it('reads the proposition id behind a store its api key', function () {
    stubAccountSettingsReader([
        settingsPathFor('live-key') => accountSettingsRow([], ['account' => ['id' => 7, 'proposition_id' => 3]]),
    ]);

    expect(makeAccountProposition('live-key')->forStore(1))->toBe(3);
});

it('answers null for a store with no api key', function () {
    stubAccountSettingsReader([]);

    expect(makeAccountProposition(null)->forStore(1))->toBeNull();
});

it('answers null without a lookup when there is no store', function () {
    expect(makeAccountProposition('live-key', 0)->forStore(null))->toBeNull();
});

it('answers null rather than throwing when the row cannot be read', function () {
    $logger = stubAccountSettingsReader([], unreadableSettings());

    expect(makeAccountProposition('live-key')->forStore(1))->toBeNull();

    $logger->shouldHaveReceived('alert')->once();
});

it('reads a store once, so a broken row costs one alert per page and not one per row', function () {
    $logger      = stubAccountSettingsReader([], unreadableSettings());
    $proposition = makeAccountProposition('live-key');

    expect($proposition->forStore(1))->toBeNull()
        ->and($proposition->forStore(1))->toBeNull();

    $logger->shouldHaveReceived('alert')->once();
});
