<?php

declare(strict_types=1);

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Quote\SaveOrderBeforeSalesModelQuoteObserver;
use MyParcelNL\Magento\Model\Sales\Repository\DeliveryRepository;
use MyParcelNL\Magento\Service\Config;

/**
 * Run the observer for a MyParcel quote whose delivery options column holds $stored.
 *
 * @return array<string, mixed> what the order was given, by field
 */
function orderDataAfterQuote(?string $stored): array
{
    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('hasData')->with(Config::FIELD_DELIVERY_OPTIONS)->andReturnTrue();
    $quote->shouldReceive('getData')->with(Config::FIELD_DELIVERY_OPTIONS)->andReturn($stored);
    $quote->shouldReceive('getShippingAddress')->andReturn(new DataObject(['shipping_method' => 'myparcel_postnl']));

    $written = [];
    $order   = Mockery::mock(Order::class);
    $order->shouldReceive('getShippingAddress')->andReturn(new DataObject());
    $order->shouldReceive('setData')->andReturnUsing(static function ($key, $value) use (&$written, $order) {
        $written[$key] = $value;

        return $order;
    });

    $delivery = Mockery::mock(DeliveryRepository::class);
    $delivery->shouldReceive('getDropOffDayFromDeliveryOptions')->andReturn('2026-11-25');
    $delivery->shouldReceive('getCarrierFromDeliveryOptions')->andReturn('postnl');

    $observer = new Observer(['event' => new DataObject(['quote' => $quote, 'order' => $order])]);

    (new SaveOrderBeforeSalesModelQuoteObserver($delivery))->execute($observer);

    return $written;
}

it('copies no delivery options from an empty column', function (?string $stored) {
    // The export would throw "Unable to unserialize value" on an order that holds ''.
    expect(orderDataAfterQuote($stored))->toBe([]);
})->with([
    'null'         => [null],
    'empty string' => [''],
]);

it('copies the delivery options, drop-off day and carrier of a choice', function () {
    $json = '{"carrier":"postnl","deliveryType":"standard"}';

    expect(orderDataAfterQuote($json))->toBe([
        Config::FIELD_DELIVERY_OPTIONS => $json,
        Config::FIELD_DROP_OFF_DAY     => '2026-11-25',
        Config::FIELD_MYPARCEL_CARRIER => 'postnl',
    ]);
});
