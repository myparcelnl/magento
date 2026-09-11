<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\TrackTrace\AccountPlatform;

/**
 * The platform id the fallback track & trace host is picked by.
 *
 * Read once per store while the order grid renders, so a keyless store and a row that cannot be read
 * at all must both answer null instead of throwing, and one store must cost one read.
 *
 * stubAccountSettingsReader() and settingsPathFor() live in Tests/Helpers/AccountSettingsHelpers.php,
 * accountSettingsRow() in Tests/Helpers/CapabilitiesFixtures.php.
 */
function makeAccountPlatform(?string $apiKey, int $keyLookups = 1): AccountPlatform
{
    $provider = Mockery::mock(ShipmentApiProvider::class);
    $provider->shouldReceive('apiKeyForStoreOrNull')->times($keyLookups)->andReturn($apiKey);

    return new AccountPlatform($provider);
}

/** A read that fails, standing for anything between the config row and the SDK throwing. */
function unreadableSettings(): callable
{
    return static function (): void {
        throw new RuntimeException('config unavailable');
    };
}

it('reads the platform id behind a store its api key', function () {
    stubAccountSettingsReader([
        settingsPathFor('live-key') => accountSettingsRow([], ['account' => ['id' => 7, 'platform_id' => 3]]),
    ]);

    expect(makeAccountPlatform('live-key')->forStore(1))->toBe(3);
});

it('answers null for a store with no api key', function () {
    stubAccountSettingsReader([]);

    expect(makeAccountPlatform(null)->forStore(1))->toBeNull();
});

it('answers null without a lookup when there is no store', function () {
    expect(makeAccountPlatform('live-key', 0)->forStore(null))->toBeNull();
});

it('answers null rather than throwing when the row cannot be read', function () {
    $logger = stubAccountSettingsReader([], unreadableSettings());

    expect(makeAccountPlatform('live-key')->forStore(1))->toBeNull();

    $logger->shouldHaveReceived('alert')->once();
});

it('reads a store once, so a broken row costs one alert per page and not one per row', function () {
    $logger   = stubAccountSettingsReader([], unreadableSettings());
    $platform = makeAccountPlatform('live-key');

    expect($platform->forStore(1))->toBeNull()
        ->and($platform->forStore(1))->toBeNull();

    $logger->shouldHaveReceived('alert')->once();
});
