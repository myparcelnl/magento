<?php

declare(strict_types=1);

use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Setup\Migrations\LegacyConfigDefaults;
use MyParcelNL\Magento\Tests\Helpers\MyParcelTokenLifecycleHarness;

const POSTNL_DELIVERY_ACTIVE = 'myparcelnl_magento_postnl_settings/delivery/active';

function legacyConfigDefaults(MyParcelTokenLifecycleHarness $harness): LegacyConfigDefaults
{
    return new LegacyConfigDefaults($harness->collectionFactory(), $harness->writer());
}

it('writes every legacy value on an install that saved nothing', function () {
    $harness = new MyParcelTokenLifecycleHarness();

    legacyConfigDefaults($harness)->run();

    expect(count($harness->rows))->toBe(count(LegacyConfigDefaults::VALUES))
        ->and($harness->rowAt(POSTNL_DELIVERY_ACTIVE))
        ->toBe(['path' => POSTNL_DELIVERY_ACTIVE, 'value' => '1', 'scope' => 'default', 'scope_id' => 0]);
});

it('keeps a value the merchant saved at default scope', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(POSTNL_DELIVERY_ACTIVE, '0', 'default', 0);

    legacyConfigDefaults($harness)->run();

    expect($harness->rowAt(POSTNL_DELIVERY_ACTIVE)['value'])->toBe('0');
});

it('writes the default under a website row, and leaves the website row alone', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(POSTNL_DELIVERY_ACTIVE, '0', ScopeInterface::SCOPE_WEBSITES, 2);

    legacyConfigDefaults($harness)->run();

    expect($harness->valueAt(ScopeInterface::SCOPE_WEBSITES, 2))->toBe('0')
        ->and(array_values(array_filter($harness->rows, static function (array $row): bool {
            return POSTNL_DELIVERY_ACTIVE === $row['path'] && 'default' === $row['scope'];
        }))[0]['value'])->toBe('1');
});

it('is a no-op on a second run', function () {
    $harness = new MyParcelTokenLifecycleHarness();

    legacyConfigDefaults($harness)->run();
    $first = $harness->rows;
    legacyConfigDefaults($harness)->run();

    expect($harness->rows)->toBe($first);
});

it('writes only paths the settings form still offers, so no row is dead on arrival', function () {
    $offered = legacyShapedBlueprint()->paths();

    expect(array_values(array_diff(array_keys(LegacyConfigDefaults::VALUES), $offered)))->toBe([]);
});
