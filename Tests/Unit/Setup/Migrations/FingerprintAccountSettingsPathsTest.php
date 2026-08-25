<?php

declare(strict_types=1);

use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Magento\Setup\Migrations\FingerprintAccountSettingsPaths;
use MyParcelNL\Magento\Tests\Helpers\MyParcelTokenLifecycleHarness;
use Psr\Log\LoggerInterface;

function fingerprintAccountSettingsMigration(MyParcelTokenLifecycleHarness $harness): FingerprintAccountSettingsPaths
{
    return new FingerprintAccountSettingsPaths(
        $harness->collectionFactory(),
        $harness->writer(),
        new Fingerprint(),
        Mockery::spy(LoggerInterface::class)
    );
}

it('rewrites a legacy plaintext-suffixed row to its fingerprinted path, preserving the value', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $apiKey  = 'legacy-key-alpha';

    // No api/key row on purpose: the rewrite must not depend on the key being configured anywhere.
    $harness->save(legacySettingsPathFor($apiKey), '{"shop":1}', 'default', 0);

    fingerprintAccountSettingsMigration($harness)->run();

    $rewritten = $harness->rowAt(settingsPathFor($apiKey));

    expect($rewritten)->not->toBeNull()
        ->and($rewritten['value'])->toBe('{"shop":1}')
        ->and($rewritten['scope'])->toBe('default')
        ->and($rewritten['scope_id'])->toBe(0)
        ->and($harness->rowAt(legacySettingsPathFor($apiKey)))->toBeNull();
});

it('moves a legacy row stored at website scope to the default-scope fingerprinted path', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $apiKey  = 'website-scoped-key';

    $harness->save(legacySettingsPathFor($apiKey), '{"shop":7}', ScopeInterface::SCOPE_WEBSITES, 3);

    fingerprintAccountSettingsMigration($harness)->run();

    $rewritten = $harness->rowAt(settingsPathFor($apiKey));

    expect($rewritten)->not->toBeNull()
        ->and($rewritten['scope'])->toBe('default')
        ->and($rewritten['scope_id'])->toBe(0)
        ->and($harness->rowAt(legacySettingsPathFor($apiKey)))->toBeNull();
});

it('keeps an existing fingerprinted row instead of overwriting it with the legacy value', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $apiKey  = 'reimported-key';

    $harness->save(legacySettingsPathFor($apiKey), '{"shop":"stale"}', 'default', 0);
    $harness->save(settingsPathFor($apiKey), '{"shop":"fresh"}', 'default', 0);

    fingerprintAccountSettingsMigration($harness)->run();

    expect($harness->rowAt(settingsPathFor($apiKey))['value'])->toBe('{"shop":"fresh"}')
        ->and($harness->rowAt(legacySettingsPathFor($apiKey)))->toBeNull();
});

it('collapses two legacy rows for one api key into a single fingerprinted row', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $apiKey  = 'duplicated-key';

    $harness->save(legacySettingsPathFor($apiKey), '{"shop":1}', 'default', 0);
    $harness->save(legacySettingsPathFor($apiKey), '{"shop":2}', ScopeInterface::SCOPE_WEBSITES, 3);

    fingerprintAccountSettingsMigration($harness)->run();

    expect($harness->rows)->toHaveCount(1)
        ->and($harness->rows[0]['path'])->toBe(settingsPathFor($apiKey))
        ->and($harness->rows[0]['scope'])->toBe('default');
});

it('leaves an already-fingerprinted row untouched', function () {
    $harness = new MyParcelTokenLifecycleHarness();

    $harness->save(settingsPathFor('already-done'), '{"shop":1}', 'default', 0);
    $before = $harness->rows;

    fingerprintAccountSettingsMigration($harness)->run();

    expect($harness->rows)->toEqual($before);
});

it('leaves config rows belonging to other settings untouched', function () {
    $harness = new MyParcelTokenLifecycleHarness();

    $harness->save('myparcelnl_magento_general/api/key', 'some-key', 'default', 0);
    $harness->save('myparcelnl_magento_general/print/paper_type', 'A4', 'default', 0);

    fingerprintAccountSettingsMigration($harness)->run();

    expect($harness->rowAt('myparcelnl_magento_general/api/key'))->not->toBeNull()
        ->and($harness->rowAt('myparcelnl_magento_general/print/paper_type')['value'])->toBe('A4');
});

it('changes nothing on a second run', function () {
    $harness = new MyParcelTokenLifecycleHarness();

    $harness->save(legacySettingsPathFor('idempotent-key'), '{"shop":1}', 'default', 0);

    fingerprintAccountSettingsMigration($harness)->run();
    $afterFirstRun = $harness->rows;

    fingerprintAccountSettingsMigration($harness)->run();

    expect($harness->rows)->toEqual($afterFirstRun);
});
