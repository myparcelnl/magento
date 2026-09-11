<?php

declare(strict_types=1);

/**
 * What getAccount() answers for a stored row AccountSettings did not write.
 *
 * The order grid reads the platform id through here while it renders, and the SDK's Account throws
 * on a missing key, so every unusable shape must come back as a null and an alert.
 *
 * accountSettingsFor() and settingsPathFor() live in Tests/Helpers/AccountSettingsHelpers.php,
 * accountSettingsRow() in Tests/Helpers/CapabilitiesFixtures.php.
 */

/** The platform id read out of one stored row, or null where no account could be built. */
function platformIdFromRow(?string $row): ?int
{
    $account = accountSettingsFor(
        'live-key',
        null === $row ? [] : [settingsPathFor('live-key') => $row]
    )['settings']->getAccount();

    return $account ? $account->getPropositionId() : null;
}

it('reads the platform id from a complete row', function () {
    expect(platformIdFromRow(accountSettingsRow([])))->toBe(1);
});

it('reads a belgian account its own platform id', function () {
    expect(platformIdFromRow(accountSettingsRow([], ['account' => ['id' => 7, 'platform_id' => 3]])))
        ->toBe(3);
});

it('prefers proposition_id, the key the api is moving to', function () {
    expect(platformIdFromRow(
        accountSettingsRow([], ['account' => ['id' => 7, 'proposition_id' => 3, 'platform_id' => 1]])
    ))->toBe(3);
});

it('answers no account when nothing is stored', function () {
    expect(platformIdFromRow(null))->toBeNull();
});

it('answers no account for a row that is not json', function () {
    expect(platformIdFromRow('{not json'))->toBeNull();
});

it('answers no account for a row that decodes to a scalar', function () {
    expect(platformIdFromRow('"just a string"'))->toBeNull();
});

it('answers no account for a row with no account in it', function () {
    expect(platformIdFromRow(accountSettingsRow([], ['account' => null])))->toBeNull();
});

it('answers no account when the account has no id', function () {
    expect(platformIdFromRow(accountSettingsRow([], ['account' => ['platform_id' => 1]])))->toBeNull();
});

// The shape that used to be a TypeError, and the one the capabilities fixture wrote before it
// carried a platform id.
it('answers no account when the account has no platform id', function () {
    expect(platformIdFromRow(accountSettingsRow([], ['account' => ['id' => 7]])))->toBeNull();
});

it('keeps the account when the row carries no usable shop', function () {
    expect(platformIdFromRow(accountSettingsRow([], ['shop' => null])))->toBe(1);
});

it('keeps the account when its general settings are not an array', function () {
    $account = accountSettingsFor('live-key', [
        settingsPathFor('live-key') => accountSettingsRow([], [
            'account' => ['id' => 7, 'platform_id' => 1, 'general_settings' => 'broken'],
        ]),
    ])['settings']->getAccount();

    expect($account)->not->toBeNull()
        ->and($account->getGeneralSettings()->hasPostnlMailboxInternational())->toBeFalse();
});

it('reads the general settings a complete row carries', function () {
    $account = accountSettingsFor('live-key', [
        settingsPathFor('live-key') => accountSettingsRow([], [
            'account' => [
                'id'               => 7,
                'platform_id'      => 1,
                'general_settings' => ['postnl_mailbox_international' => true],
            ],
        ]),
    ])['settings']->getAccount();

    expect($account->getGeneralSettings()->hasPostnlMailboxInternational())->toBeTrue();
});

it('alerts with a redacted api key when a row cannot be used', function () {
    $made = accountSettingsFor('live-key-1234567890', [
        settingsPathFor('live-key-1234567890') => accountSettingsRow([], ['account' => ['id' => 7]]),
    ]);

    expect($made['settings']->getAccount())->toBeNull();

    $made['logger']->shouldHaveReceived('alert')
                   ->once()
                   ->with(Mockery::on(static fn ($message): bool => is_string($message)
                       && str_contains($message, 'Account settings are incomplete')
                       && str_contains($message, 'live***********7890')
                       && ! str_contains($message, 'live-key-1234567890')));
});
