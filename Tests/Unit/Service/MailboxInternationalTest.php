<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Magento\Service\MailboxInternational;
use Psr\Log\LoggerInterface;

/**
 * AccountSettings reads the stored row through the static ObjectManager, so the row is seeded there
 * rather than injected. That indirection is exactly what this class exists to keep out of the
 * package type decision.
 */
function mailboxInternationalFor(array $rowsByPath, string $apiKey = 'live-key'): MailboxInternational
{
    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(ScopeConfigInterface::class)->andReturn(mockScopeConfig($rowsByPath));
    $objectManager->shouldReceive('get')->with(Fingerprint::class)->andReturn(new Fingerprint());
    $objectManager->shouldReceive('get')->with(LoggerInterface::class)
                  ->andReturn(Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing());
    ObjectManager::setInstance($objectManager);

    return new MailboxInternational(createConfig(['api/key' => $apiKey]));
}

function accountRowWithGeneralSettings(array $generalSettings): string
{
    return accountSettingsRow([], [
        'account' => ['id' => 7, 'proposition_id' => 1, 'general_settings' => $generalSettings],
    ]);
}

it('answers true when the account allows an international mailbox', function () {
    $service = mailboxInternationalFor([
        settingsPathFor('live-key') => accountRowWithGeneralSettings(['postnl_mailbox_international' => true]),
    ]);

    expect($service->isEnabledFor('postnl', 1))->toBeTrue();
});

it('answers false when the account forbids it', function () {
    $service = mailboxInternationalFor([
        settingsPathFor('live-key') => accountRowWithGeneralSettings(['postnl_mailbox_international' => false]),
    ]);

    expect($service->isEnabledFor('postnl', 1))->toBeFalse();
});

it('answers false when the account says nothing about it', function () {
    $service = mailboxInternationalFor([
        settingsPathFor('live-key') => accountRowWithGeneralSettings([]),
    ]);

    expect($service->isEnabledFor('postnl', 1))->toBeFalse();
});

it('answers false when there is no stored row for the key', function () {
    expect(mailboxInternationalFor([])->isEnabledFor('postnl', 1))->toBeFalse();
});

it('does not read the account of another key when the store has no api key', function () {
    // An empty key still fingerprints, so it addresses its own row rather than failing. What it must
    // never do is fall through to a key that belongs to some other store.
    $service = mailboxInternationalFor([
        settingsPathFor('live-key') => accountRowWithGeneralSettings(['postnl_mailbox_international' => true]),
    ], '');

    expect($service->isEnabledFor('postnl', null))->toBeFalse();
});

it('lists every carrier the account flags, by api key', function () {
    $service = mailboxInternationalFor([
        settingsPathFor('live-key') => accountRowWithGeneralSettings([
            'postnl_mailbox_international' => true,
            'dpd_mailbox_international'    => true,
        ]),
    ]);

    expect($service->carriersFor('live-key'))->toBe(['postnl', 'dpd'])
        ->and($service->carriersFor('other-key'))->toBe([]);
});

it('answers per carrier, from the flag that names it', function () {
    $service = mailboxInternationalFor([
        settingsPathFor('live-key') => accountRowWithGeneralSettings(['postnl_mailbox_international' => true]),
    ]);

    expect($service->isEnabledFor('postnl', 1))->toBeTrue()
        ->and($service->isEnabledFor('dpd', 1))->toBeFalse();
});
