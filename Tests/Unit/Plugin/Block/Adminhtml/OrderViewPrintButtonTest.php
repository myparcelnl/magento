<?php

declare(strict_types=1);

use Magento\Sales\Block\Adminhtml\Order\View as OrderView;
use MyParcelNL\Magento\Plugin\Block\Adminhtml\Order\View;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;

/** Order v1 makes no label in Magento, so the order page offers neither print nor return label. */
function orderViewButtons(bool $orderV1): array
{
    $buttons = [];
    $view    = Mockery::mock(OrderView::class);
    $view->shouldReceive('getOrder')->andReturn(createOrder(['getStoreId' => 3, 'hasShipments' => true]));
    $view->shouldReceive('addButton')->andReturnUsing(static function (string $id) use (&$buttons) {
        $buttons[] = $id;
    });

    $storedAccount = Mockery::mock(StoredAccount::class);
    $storedAccount->shouldReceive('hasOrderV1ForStore')->with(3)->andReturn($orderV1);

    (new View($storedAccount))->beforeSetLayout($view);

    return $buttons;
}

it('offers the print and return label buttons on a shipments store', function () {
    expect(orderViewButtons(false))->toBe(['myparcelnl_print_label', 'myparcelnl_print_retour_label']);
});

it('offers neither on an order v1 store', function () {
    expect(orderViewButtons(true))->toBe([]);
});
