<?php

declare(strict_types=1);

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Quote\SaveOrderBeforeSalesModelQuoteObserver;
use MyParcelNL\Magento\Model\Sales\Repository\DeliveryRepository;
use MyParcelNL\Magento\Service\Config;

/** The quote holds the widget's raw output; the order must store an unticked option as inherit. */
it('stores an option the customer did not tick as inherit on the order', function () {
    $raw = json_encode([
        'carrier'         => 'postnl',
        'deliveryType'    => 'standard',
        'shipmentOptions' => ['signature' => false, 'onlyRecipient' => true],
    ]);

    $address = new DataObject(['shipping_method' => 'myparcel_postnl']);
    $quote   = Mockery::mock(Quote::class);
    $quote->shouldReceive('hasData')->with(Config::FIELD_DELIVERY_OPTIONS)->andReturn(true);
    $quote->shouldReceive('getData')->with(Config::FIELD_DELIVERY_OPTIONS)->andReturn($raw);
    $quote->shouldReceive('getShippingAddress')->andReturn($address);

    $stored = [];
    $order  = Mockery::mock(Order::class);
    $order->shouldReceive('getShippingAddress')->andReturn($address);
    $order->shouldReceive('setData')->andReturnUsing(function (string $key, $value) use (&$stored, $order) {
        $stored[$key] = $value;

        return $order;
    });

    $delivery = Mockery::mock(DeliveryRepository::class);
    $delivery->shouldReceive('getDropOffDayFromDeliveryOptions')->andReturn(null);
    $delivery->shouldReceive('getCarrierFromDeliveryOptions')->andReturn('postnl');

    $observer = new Observer(['event' => new DataObject(['quote' => $quote, 'order' => $order])]);

    (new SaveOrderBeforeSalesModelQuoteObserver($delivery))->execute($observer);

    expect(json_decode($stored[Config::FIELD_DELIVERY_OPTIONS], true)['shipmentOptions'])
        ->toBe(['signature' => null, 'onlyRecipient' => true]);
});
