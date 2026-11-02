<?php

declare(strict_types=1);

use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Block\DataProviders\Email\Shipment\TrackingUrl;

/**
 * Builds the track and trace url for a shipment track whose order has the given shipping address.
 */
function trackingUrlForAddress(?string $postcode, string $countryId): string
{
    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getPostcode')->andReturn($postcode);
    $address->shouldReceive('getCountryId')->andReturn($countryId);

    $order = Mockery::mock(Order::class);
    $order->shouldReceive('load')->andReturnSelf();
    $order->shouldReceive('getShippingAddress')->andReturn($address);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->with(Order::class)->andReturn($order);
    ObjectManager::setInstance($objectManager);

    $track = Mockery::mock(Track::class);
    $track->shouldReceive('getOrderId')->andReturn(1);
    $track->shouldReceive('getNumber')->andReturn('3SMYPA000000001');

    return (new TrackingUrl())->getUrl($track);
}

afterEach(function () {
    (new ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, null);
});

it('builds the url with the postcode without spaces', function () {
    expect(trackingUrlForAddress('2132 JE', 'NL'))->toEndWith('/3SMYPA000000001/2132JE/NL');
});

it('builds the url instead of crashing when the postcode is null', function () {
    expect(trackingUrlForAddress(null, 'BE'))->toEndWith('/3SMYPA000000001//BE');
});
