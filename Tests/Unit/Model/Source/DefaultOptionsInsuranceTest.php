<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;

/**
 * What the merchant's configuration asks for, before the contract range is applied. The clamp is
 * ShipmentOptionsResolver's, and is deliberately not exercised here (DR-19).
 *
 * @param array<string, mixed> $carrierSettings the carrier's default_options
 */
function defaultOptionsFor(array $carrierSettings, float $grandTotal, ?string $countryId = 'NL'): DefaultOptions
{
    $config = createConfig([], [Carrier::POSTNL => ['default_options' => $carrierSettings]]);
    mockLoggerFacade([Config::class => $config]);

    $address = null;

    if (null !== $countryId) {
        $address = Mockery::mock(Address::class);
        $address->shouldReceive('getCountryId')->andReturn($countryId);
    }

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getData')->andReturn(null);
    $quote->shouldReceive('getShippingAddress')->andReturn($address);
    $quote->shouldReceive('getGrandTotal')->andReturn($grandTotal);
    $quote->shouldReceive('getStoreId')->andReturn(1);

    return new DefaultOptions($quote);
}

function insuranceSettings(array $overrides = []): array
{
    return array_replace([
        'insurance_from_price'     => 0,
        'insurance_percentage'     => 100,
        'insurance_local_amount'   => 5000,
        'insurance_belgium_amount' => 2000,
        'insurance_eu_amount'      => 500,
        'insurance_row_amount'     => 0,
    ], $overrides);
}

it('insures the order value, not a tier above it', function () {
    $options = defaultOptionsFor(insuranceSettings(), 137.00);

    expect($options->getDefaultInsurance(Carrier::POSTNL))->toBe(137);
});

it('rounds a fractional order value up, because under-insuring is the worse error', function () {
    $options = defaultOptionsFor(insuranceSettings(), 137.01);

    expect($options->getDefaultInsurance(Carrier::POSTNL))->toBe(138);
});

it('never insures above the configured cap', function () {
    $options = defaultOptionsFor(insuranceSettings(['insurance_local_amount' => 250]), 9000.00);

    expect($options->getDefaultInsurance(Carrier::POSTNL))->toBe(250);
});

it('applies the percentage before matching', function () {
    $options = defaultOptionsFor(insuranceSettings(['insurance_percentage' => 50]), 400.00);

    expect($options->getDefaultInsurance(Carrier::POSTNL))->toBe(200);
});

it('treats a cap of zero as insurance switched off', function () {
    $options = defaultOptionsFor(insuranceSettings(['insurance_local_amount' => 0]), 400.00);

    expect($options->getDefaultInsurance(Carrier::POSTNL))->toBe(0);
});

it('insures nothing below the configured from-price', function () {
    $options = defaultOptionsFor(insuranceSettings(['insurance_from_price' => 500]), 400.00);

    expect($options->getDefaultInsurance(Carrier::POSTNL))->toBe(0);
});

it('picks the cap belonging to the destination zone', function () {
    expect(defaultOptionsFor(insuranceSettings(), 9000.00, 'NL')->getDefaultInsurance(Carrier::POSTNL))->toBe(5000)
        ->and(defaultOptionsFor(insuranceSettings(), 9000.00, 'BE')->getDefaultInsurance(Carrier::POSTNL))->toBe(2000)
        ->and(defaultOptionsFor(insuranceSettings(), 9000.00, 'DE')->getDefaultInsurance(Carrier::POSTNL))->toBe(500)
        ->and(defaultOptionsFor(insuranceSettings(), 9000.00, 'US')->getDefaultInsurance(Carrier::POSTNL))->toBe(0);
});

it('falls back to the domestic cap for an order with no shipping address', function () {
    $options = defaultOptionsFor(insuranceSettings(), 9000.00, null);

    expect($options->getDefaultInsurance(Carrier::POSTNL))->toBe(5000);
});
