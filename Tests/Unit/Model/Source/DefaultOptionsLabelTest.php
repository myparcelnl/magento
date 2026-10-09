<?php

declare(strict_types=1);

use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;

/** What a merchant saved for the label wins over the configuration; nothing saved changes nothing. */
function storedLabelOptions(array $label): string
{
    return json_encode(['deliveryType' => 'standard'] + $label);
}

it('answers the saved label amount, or one', function () {
    expect(defaultOptionsFor([], 10.0, 'NL', 'NL', storedLabelOptions(['labelAmount' => 3]))->getLabelAmount())->toBe(3)
        ->and(defaultOptionsFor([], 10.0)->getLabelAmount())->toBe(1);
});

it('totals the saved label amounts of a selection, counting one where none is saved', function () {
    mockLoggerFacade([Config::class => createConfig()]);

    $orders = array_map(static function (?string $stored): Order {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('getData')->andReturn($stored);

        return $order;
    }, [storedLabelOptions(['labelAmount' => 3]), null]);

    expect(DefaultOptions::labelTotal(array_slice($orders, 0, 1)))->toBe(3)
        ->and(DefaultOptions::labelTotal($orders))->toBe(4);
});

it('answers the saved digital stamp weight before the configured one', function () {
    expect(defaultOptionsFor([], 10.0, 'NL', 'NL', storedLabelOptions(['digitalStampWeight' => 350]))->getDigitalStampDefaultWeight())
        ->toBe(350);
});
