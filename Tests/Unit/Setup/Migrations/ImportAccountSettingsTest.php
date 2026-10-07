<?php

declare(strict_types=1);

use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Magento\Setup\Migrations\ImportAccountSettings;
use MyParcelNL\Magento\Tests\Helpers\MyParcelTokenLifecycleHarness;
use Psr\Log\LoggerInterface;

it('imports each stored api key once, and keeps going past one that fails', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save('myparcelnl_magento_general/api/key', 'key-a', 'default', 0);
    $harness->save('myparcelnl_magento_general/api/key', 'key-b', ScopeInterface::SCOPE_WEBSITES, 2);
    $harness->save('myparcelnl_magento_general/api/key', 'key-a', ScopeInterface::SCOPE_STORES, 3);
    $harness->save('myparcelnl_magento_general/api/key', '', ScopeInterface::SCOPE_STORES, 4);

    $importer = Mockery::mock(Importer::class);
    $importer->shouldReceive('importFor')->once()->with('key-a')->andThrow(new RuntimeException('down'));
    $importer->shouldReceive('importFor')->once()->with('key-b')->andReturn(true);

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once()->with(Mockery::on(
        static fn($message): bool => ! str_contains($message, 'key-a')
    ), Mockery::any());

    (new ImportAccountSettings($harness->collectionFactory(), $importer, new Fingerprint(), $logger))->run();
});
