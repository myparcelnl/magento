<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\Tax;

function pickupPriceSettings(bool $totalView, float $pickupFee): array
{
    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn([])->byDefault();
    $quote->shouldReceive('getStoreId')->andReturn(1)->byDefault();

    $tax = Mockery::mock(Tax::class);
    $tax->shouldReceive('shippingPrice')->andReturnUsing(static fn ($price) => $price * 2)->byDefault();

    $deliveryCosts = Mockery::mock(DeliveryCosts::class);
    // once(): the checkout sums the order amount once per request and hands it to every lookup;
    // a lookup without it would price standard at 99.0
    $deliveryCosts->shouldReceive('getOrderAmount')->once()->andReturn(42.0);
    $deliveryCosts->shouldReceive('getBasePriceForClient')->andReturnUsing(
        static fn ($quote, $carrier, $packageType, $country, $deliveryType, $orderAmount) => 'pickup' === $deliveryType ? 0.0 : (42.0 === $orderAmount ? 6.95 : 99.0)
    )->byDefault();

    return checkoutCarrierSettings(checkoutPriceConfig($totalView, ['pickup/fee' => $pickupFee]), $tax, $deliveryCosts, $quote);
}

it('shows free pickup as the difference with standard delivery in surcharge view', function () {
    $settings = pickupPriceSettings(false, 0.0);

    expect($settings['pricePickup'])->toBe(-6.95)
        ->and($settings['priceStandardDelivery'])->toBe(0.0);
});

it('shows free pickup as its total in total view', function () {
    $settings = pickupPriceSettings(true, 0.0);

    expect($settings['pricePickup'])->toBe(0.0)
        ->and($settings['priceStandardDelivery'])->toBe(6.95);
});

it('passes the pickup fee through the tax settings like every other fee', function () {
    // the doubling tax mock turns the 2.00 fee into 4.00: pickup base 0 - standard 6.95 + 4.00
    expect(pickupPriceSettings(false, 2.0)['pricePickup'])->toBe(-2.95);
});
