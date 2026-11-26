<?php

declare(strict_types=1);

use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Model\AbstractModel;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Observer\CreateConceptAfterInvoice;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\Config;

it('skips the export after an invoice and logs why when the account features are unknown', function () {
    $logger = stubAccountSettingsReader([
        settingsPathFor('old-row-key') => accountSettingsRow([], ['account' => ['id' => 6, 'proposition_id' => 1]]),
    ]);
    $logger->shouldReceive('warning')->once()->with(
        'Order 5 was not exported after its invoice: ' . StoredAccount::ERROR_FEATURES_UNKNOWN
    );

    $objectManager = Mockery::mock(ObjectManager::class);
    $objectManager->shouldReceive('get')->with(StoredAccount::class)->andReturn(storedAccountForStores([3 => 'old-row-key']));

    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getGeneralConfig')->with('print/create_concept_after_invoice')->andReturn('1');

    // AbstractModel, not createOrder(): the observer checks instanceof, and the Order test stub is no AbstractModel.
    $order = Mockery::mock(AbstractModel::class, ['getId' => 5, 'getStoreId' => 3, 'getState' => 'processing']);
    $event = Mockery::mock(Observer::class);
    $event->shouldReceive('getEvent')->andReturn(new DataObject(['invoice' => new DataObject(['order' => $order])]));

    $observer = newInstanceWithoutConstructor(CreateConceptAfterInvoice::class);
    setPrivateProperty($observer, 'objectManager', $objectManager);
    setPrivateProperty($observer, 'config', $config);
    // Unstubbed: creating the Magento shipment, the first step of an export, fails this test.
    setPrivateProperty($observer, 'orderCollection', Mockery::mock(MagentoOrderCollection::class));

    expect($observer->execute($event))->toBe($observer);
});
