<?php

declare(strict_types=1);

/** What the configuration asks for, before the contract range: that clamp is ShipmentOptionsResolver's. */
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

it('insures for the amount stored on the order, zero included', function () {
    $stored = static function (int $amount): string {
        return json_encode(['deliveryType' => 'standard', 'shipmentOptions' => ['insurance' => $amount]]);
    };

    expect(defaultOptionsFor(insuranceSettings(), 137.00, 'NL', 'NL', $stored(500))->getDefaultInsurance('postnl'))->toBe(500)
        ->and(defaultOptionsFor(insuranceSettings(), 137.00, 'NL', 'NL', $stored(0))->getDefaultInsurance('postnl'))->toBe(0);
});

it('insures the order value, not a tier above it', function () {
    $options = defaultOptionsFor(insuranceSettings(), 137.00);

    expect($options->getDefaultInsurance('postnl'))->toBe(137);
});

it('rounds a fractional order value up, because under-insuring is the worse error', function () {
    $options = defaultOptionsFor(insuranceSettings(), 137.01);

    expect($options->getDefaultInsurance('postnl'))->toBe(138);
});

it('never insures above the configured cap', function () {
    $options = defaultOptionsFor(insuranceSettings(['insurance_local_amount' => 250]), 9000.00);

    expect($options->getDefaultInsurance('postnl'))->toBe(250);
});

it('applies the percentage before matching', function () {
    $options = defaultOptionsFor(insuranceSettings(['insurance_percentage' => 50]), 400.00);

    expect($options->getDefaultInsurance('postnl'))->toBe(200);
});

it('treats a cap of zero as insurance switched off', function () {
    $options = defaultOptionsFor(insuranceSettings(['insurance_local_amount' => 0]), 400.00);

    expect($options->getDefaultInsurance('postnl'))->toBe(0);
});

it('insures nothing below the configured from-price', function () {
    $options = defaultOptionsFor(insuranceSettings(['insurance_from_price' => 500]), 400.00);

    expect($options->getDefaultInsurance('postnl'))->toBe(0);
});

it('picks the cap belonging to the destination zone', function () {
    expect(defaultOptionsFor(insuranceSettings(), 9000.00, 'NL')->getDefaultInsurance('postnl'))->toBe(5000)
        ->and(defaultOptionsFor(insuranceSettings(), 9000.00, 'BE')->getDefaultInsurance('postnl'))->toBe(2000)
        ->and(defaultOptionsFor(insuranceSettings(), 9000.00, 'DE')->getDefaultInsurance('postnl'))->toBe(500)
        ->and(defaultOptionsFor(insuranceSettings(), 9000.00, 'US')->getDefaultInsurance('postnl'))->toBe(0);
});

it('falls back to the domestic cap for an order with no shipping address', function () {
    $options = defaultOptionsFor(insuranceSettings(), 9000.00, null);

    expect($options->getDefaultInsurance('postnl'))->toBe(5000);
});

// getRequiredInsurance: the amount when another option requires insurance

it('insures a required companion below the from-price', function () {
    $options = defaultOptionsFor(insuranceSettings(['insurance_from_price' => 500]), 400.00);

    expect($options->getRequiredInsurance('postnl'))->toBe(400);
});

it('applies the percentage and the cap to a required companion', function () {
    expect(defaultOptionsFor(insuranceSettings(['insurance_percentage' => 50]), 400.00)->getRequiredInsurance('postnl'))->toBe(200)
        ->and(defaultOptionsFor(insuranceSettings(['insurance_local_amount' => 250]), 9000.00)->getRequiredInsurance('postnl'))->toBe(250)
        ->and(defaultOptionsFor(insuranceSettings(['insurance_local_amount' => 0]), 400.00)->getRequiredInsurance('postnl'))->toBe(0);
});

it('caps Belgium by the zone the account its home country puts it in', function (string $homeCountry, int $cap) {
    expect(defaultOptionsFor(insuranceSettings(), 9000.00, 'BE', $homeCountry)->getDefaultInsurance('postnl'))->toBe($cap);
})->with([
    'belgian account, local amount' => ['BE', 5000],
    'dutch account, belgian amount' => ['NL', 2000],
]);
