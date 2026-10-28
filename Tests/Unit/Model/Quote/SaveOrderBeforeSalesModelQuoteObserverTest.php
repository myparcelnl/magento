<?php

declare(strict_types=1);

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use MyParcelNL\Magento\Model\Quote\SaveOrderBeforeSalesModelQuoteObserver;
use MyParcelNL\Magento\Model\Sales\Repository\DeliveryRepository;
use MyParcelNL\Magento\Service\Config;

/**
 * Runs the observer for a shipping address and returns the track status it set on the order, or null.
 */
function runObserverForAddress(?string $postcode, ?string $countryId, array $street = ['Antareslaan 31']): ?string
{
    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getStreet')->andReturn($street);
    $address->shouldReceive('getPostcode')->andReturn($postcode);
    $address->shouldReceive('getCountryId')->andReturn($countryId);

    $trackStatus = null;
    $order       = Mockery::mock(Order::class);
    $order->shouldReceive('getShippingAddress')->andReturn($address);
    $order->shouldReceive('setData')
        ->andReturnUsing(function (string $key, $value) use (&$trackStatus, $order) {
            if (Config::FIELD_TRACK_STATUS === $key) {
                $trackStatus = (string) $value;
            }

            return $order;
        });

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('hasData')->andReturn(false);

    $event = Mockery::mock(Event::class);
    $event->shouldReceive('getData')->with('quote')->andReturn($quote);
    $event->shouldReceive('getData')->with('order')->andReturn($order);

    $observer = Mockery::mock(Observer::class);
    $observer->shouldReceive('getEvent')->andReturn($event);

    (new SaveOrderBeforeSalesModelQuoteObserver(Mockery::mock(DeliveryRepository::class)))->execute($observer);

    return $trackStatus;
}

it('does not set a warning for a valid dutch address', function () {
    expect(runObserverForAddress('2132 JE', 'NL'))->toBeNull();
});

it('warns about the postal code when it is invalid', function () {
    expect(runObserverForAddress('12345', 'NL'))->toContain('Please check postal code');
});

it('warns about the postal code being null', function () {
    expect(runObserverForAddress(null, 'NL'))->toContain('Please check postal code');
});

it('warns about the postal code being null for belgium', function () {
    expect(runObserverForAddress(null, 'BE'))->toContain('Please check postal code');
});

it('does not set a warning when the country is null', function () {
    expect(runObserverForAddress('1234', null))->toBeNull();
});
