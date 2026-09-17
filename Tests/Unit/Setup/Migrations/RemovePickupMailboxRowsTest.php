<?php

declare(strict_types=1);

use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Setup\Migrations\RemovePickupMailboxRows;
use MyParcelNL\Magento\Tests\Helpers\MyParcelTokenLifecycleHarness;

function removePickupMailboxRows(MyParcelTokenLifecycleHarness $harness): RemovePickupMailboxRows
{
    return new RemovePickupMailboxRows($harness->collectionFactory(), $harness->writer());
}

it('removes the dead rows for every carrier and every scope', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save('myparcelnl_magento_postnl_settings/mailbox/pickup_mailbox', '1', 'default', 0);
    $harness->save('myparcelnl_magento_dpd_settings/mailbox/pickup_mailbox', '0', ScopeInterface::SCOPE_WEBSITES, 2);
    $harness->save('myparcelnl_magento_dhlforyou_settings/mailbox/pickup_mailbox', '1', ScopeInterface::SCOPE_STORES, 5);

    removePickupMailboxRows($harness)->run();

    expect($harness->rows)->toBe([]);
});

it('leaves the mailbox settings that are still read', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save('myparcelnl_magento_postnl_settings/mailbox/pickup_mailbox', '1', 'default', 0);
    $harness->save('myparcelnl_magento_postnl_settings/mailbox/active', '1', 'default', 0);
    $harness->save('myparcelnl_magento_postnl_settings/mailbox/weight', '2000', 'default', 0);

    removePickupMailboxRows($harness)->run();

    expect($harness->rowAt('myparcelnl_magento_postnl_settings/mailbox/pickup_mailbox'))->toBeNull()
        ->and($harness->rowAt('myparcelnl_magento_postnl_settings/mailbox/active'))->not->toBeNull()
        ->and($harness->rowAt('myparcelnl_magento_postnl_settings/mailbox/weight'))->not->toBeNull();
});

it('is a no-op on a second run', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save('myparcelnl_magento_postnl_settings/mailbox/pickup_mailbox', '1', 'default', 0);

    removePickupMailboxRows($harness)->run();
    removePickupMailboxRows($harness)->run();

    expect($harness->rows)->toBe([]);
});
