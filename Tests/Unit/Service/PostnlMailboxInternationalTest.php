<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Magento\Service\PostnlMailboxInternational;
use Psr\Log\LoggerInterface;

/**
 * AccountSettings reads the stored row through the static ObjectManager, so the row is seeded there
 * rather than injected. That indirection is exactly what this class exists to keep out of the
 * package type decision.
 */
function postnlMailboxInternationalFor(array $rowsByPath, string $apiKey = 'live-key'): PostnlMailboxInternational
{
    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(ScopeConfigInterface::class)->andReturn(mockScopeConfig($rowsByPath));
    $objectManager->shouldReceive('get')->with(Fingerprint::class)->andReturn(new Fingerprint());
    $objectManager->shouldReceive('get')->with(LoggerInterface::class)
                  ->andReturn(Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing());
    ObjectManager::setInstance($objectManager);

    return new PostnlMailboxInternational(createConfig(['api/key' => $apiKey]));
}

function accountRowWithGeneralSettings(array $generalSettings): string
{
    return accountSettingsRow([], [
        'account' => ['id' => 7, 'platform_id' => 1, 'general_settings' => $generalSettings],
    ]);
}

it('answers true when the account allows an international mailbox', function () {
    $service = postnlMailboxInternationalFor([
        settingsPathFor('live-key') => accountRowWithGeneralSettings(['postnl_mailbox_international' => true]),
    ]);

    expect($service->isEnabled(1))->toBeTrue();
});

it('answers false when the account forbids it', function () {
    $service = postnlMailboxInternationalFor([
        settingsPathFor('live-key') => accountRowWithGeneralSettings(['postnl_mailbox_international' => false]),
    ]);

    expect($service->isEnabled(1))->toBeFalse();
});

it('answers false when the account says nothing about it', function () {
    $service = postnlMailboxInternationalFor([
        settingsPathFor('live-key') => accountRowWithGeneralSettings([]),
    ]);

    expect($service->isEnabled(1))->toBeFalse();
});

it('answers false when there is no stored row for the key', function () {
    expect(postnlMailboxInternationalFor([])->isEnabled(1))->toBeFalse();
});

it('does not read the account of another key when the store has no api key', function () {
    // An empty key still fingerprints, so it addresses its own row rather than failing. What it must
    // never do is fall through to a key that belongs to some other store.
    $service = postnlMailboxInternationalFor([
        settingsPathFor('live-key') => accountRowWithGeneralSettings(['postnl_mailbox_international' => true]),
    ], '');

    expect($service->isEnabled(null))->toBeFalse();
});
