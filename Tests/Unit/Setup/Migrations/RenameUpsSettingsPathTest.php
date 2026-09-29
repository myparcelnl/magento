<?php

declare(strict_types=1);

use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Setup\Migrations\RenameUpsSettingsPath;
use MyParcelNL\Magento\Tests\Helpers\MyParcelTokenLifecycleHarness;

function renameUpsSettingsPath(MyParcelTokenLifecycleHarness $harness): RenameUpsSettingsPath
{
    return new RenameUpsSettingsPath($harness->collectionFactory(), $harness->writer());
}

it('moves every ups row to the upsstandard path, in every scope', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save('myparcelnl_magento_ups_settings/delivery/active', '1', 'default', 0);
    $harness->save('myparcelnl_magento_ups_settings/delivery/signature_fee', '1.5', ScopeInterface::SCOPE_WEBSITES, 2);
    $harness->save('myparcelnl_magento_ups_settings/default_options/insurance_local_amount', '500', ScopeInterface::SCOPE_STORES, 5);

    renameUpsSettingsPath($harness)->run();

    expect($harness->rows)->toEqualCanonicalizing([
        ['path' => 'myparcelnl_magento_upsstandard_settings/delivery/active', 'value' => '1', 'scope' => 'default', 'scope_id' => 0],
        ['path' => 'myparcelnl_magento_upsstandard_settings/delivery/signature_fee', 'value' => '1.5', 'scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 2],
        ['path' => 'myparcelnl_magento_upsstandard_settings/default_options/insurance_local_amount', 'value' => '500', 'scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 5],
    ]);
});

it('leaves paths the LIKE wildcards also match', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save('myparcelnl_magento_upsXsettings/delivery/active', '1', 'default', 0);
    $harness->save('myparcelnl_magento_postnl_settings/delivery/active', '1', 'default', 0);

    renameUpsSettingsPath($harness)->run();

    expect($harness->rowAt('myparcelnl_magento_upsXsettings/delivery/active'))->not->toBeNull()
        ->and($harness->rowAt('myparcelnl_magento_postnl_settings/delivery/active'))->not->toBeNull()
        ->and($harness->rows)->toHaveCount(2);
});

it('keeps a value already saved under the new path, and drops the old row', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save('myparcelnl_magento_ups_settings/delivery/active', '0', 'default', 0);
    $harness->save('myparcelnl_magento_upsstandard_settings/delivery/active', '1', 'default', 0);

    renameUpsSettingsPath($harness)->run();

    expect($harness->rows)->toBe([
        ['path' => 'myparcelnl_magento_upsstandard_settings/delivery/active', 'value' => '1', 'scope' => 'default', 'scope_id' => 0],
    ]);
});

it('is a no-op on a second run', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save('myparcelnl_magento_ups_settings/delivery/active', '1', 'default', 0);

    renameUpsSettingsPath($harness)->run();
    $afterFirst = $harness->rows;
    renameUpsSettingsPath($harness)->run();

    expect($harness->rows)->toBe($afterFirst);
});
