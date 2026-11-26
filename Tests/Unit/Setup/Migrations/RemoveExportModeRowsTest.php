<?php

declare(strict_types=1);

use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Setup\Migrations\RemoveExportModeRows;
use MyParcelNL\Magento\Tests\Helpers\MyParcelTokenLifecycleHarness;

function removeExportModeRows(MyParcelTokenLifecycleHarness $harness): RemoveExportModeRows
{
    return new RemoveExportModeRows($harness->collectionFactory(), $harness->writer());
}

it('removes the export mode at every scope and leaves the other print settings', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save('myparcelnl_magento_general/print/export_mode', 'pps', 'default', 0);
    $harness->save('myparcelnl_magento_general/print/export_mode', 'shipments', ScopeInterface::SCOPE_WEBSITES, 2);
    $harness->save('myparcelnl_magento_general/print/paper_type', 'A4', 'default', 0);

    removeExportModeRows($harness)->run();

    expect($harness->rowAt('myparcelnl_magento_general/print/export_mode'))->toBeNull()
        ->and($harness->rows)->toHaveCount(1)
        ->and($harness->rowAt('myparcelnl_magento_general/print/paper_type'))->not->toBeNull();
});

it('is a no-op on a second run', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save('myparcelnl_magento_general/print/export_mode', 'pps', 'default', 0);

    removeExportModeRows($harness)->run();
    removeExportModeRows($harness)->run();

    expect($harness->rows)->toBe([]);
});
