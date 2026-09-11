<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Capabilities\InsuranceRange;

it('reads the flat properties and converts cents to euros', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    $range = InsuranceRange::fromOptionValue([
        'min'     => ['amount' => 0, 'currency' => 'EUR'],
        'max'     => ['amount' => 500000, 'currency' => 'EUR'],
        'default' => ['amount' => 10000, 'currency' => 'EUR'],
    ]);

    expect($range->min())->toBe(0)
        ->and($range->max())->toBe(5000)
        ->and($range->default())->toBe(100);
});

it('ignores the deprecated insuredAmount wrapper', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    expect(InsuranceRange::fromOptionValue([
        'isRequired'    => true,
        'insuredAmount' => [
            'min'     => ['amount' => 5000, 'currency' => 'EUR'],
            'max'     => ['amount' => 250000, 'currency' => 'EUR'],
            'default' => ['amount' => 50000, 'currency' => 'EUR'],
        ],
    ]))->toBeNull();
});

it('rounds inwards so a fractional bound cannot widen the range', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    $range = InsuranceRange::fromOptionValue([
        'min' => ['amount' => 1050],
        'max' => ['amount' => 249950],
    ]);

    expect($range->min())->toBe(11)
        ->and($range->max())->toBe(2499);
});

it('treats a missing minimum as zero', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    expect(InsuranceRange::fromOptionValue(['max' => ['amount' => 100000]])->min())->toBe(0);
});

it('answers null when the option is absent', function () {
    expect(InsuranceRange::fromOptionValue(null))->toBeNull()
        ->and(InsuranceRange::fromOptionValue([]))->toBeNull();
});

it('answers null when no maximum is named, so the caller falls open', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    expect(InsuranceRange::fromOptionValue(['isRequired' => false]))->toBeNull()
        ->and(InsuranceRange::fromOptionValue(['min' => ['amount' => 100]]))->toBeNull();
});

it('answers null for a bound that is a bare number, whose scale is unstated', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    expect(InsuranceRange::fromOptionValue(['min' => 0, 'max' => 500000]))->toBeNull();
});

it('answers null for a maximum below one euro, so clamp cannot zero an amount', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    expect(InsuranceRange::fromOptionValue(['max' => ['amount' => 50]]))->toBeNull()
        ->and(InsuranceRange::fromOptionValue(['min' => ['amount' => 0], 'max' => ['amount' => 99]]))
        ->toBeNull();
});

it('answers null for a range whose rounded minimum exceeds its maximum', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    expect(InsuranceRange::fromOptionValue(['min' => ['amount' => 150], 'max' => ['amount' => 100]]))
        ->toBeNull();
});

it('clamps to the nearest bound and never to zero', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    $range = InsuranceRange::fromOptionValue([
        'min' => ['amount' => 10000],
        'max' => ['amount' => 250000],
    ]);

    expect($range->clamp(137))->toBe(137)
        ->and($range->clamp(9000))->toBe(2500)
        ->and($range->clamp(5))->toBe(100)
        ->and($range->clamp(0))->toBe(100);
});

it('treats an option that does not say it is required as optional', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    $range = InsuranceRange::fromOptionValue(['max' => ['amount' => 50000]]);

    expect($range->isRequired())->toBeFalse()
        ->and($range->allows(0))->toBeTrue();
});

it('allows zero for an optional contract, even one with a minimum above zero', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    $range = InsuranceRange::fromOptionValue([
        'isRequired' => false,
        'min'        => ['amount' => 10000],
        'max'        => ['amount' => 250000],
    ]);

    expect($range->allows(0))->toBeTrue()
        ->and($range->allows(50))->toBeFalse()
        ->and($range->allows(100))->toBeTrue()
        ->and($range->lowestAccepted())->toBe(0);
});

it('refuses zero for a contract that requires insurance', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    $range = InsuranceRange::fromOptionValue([
        'isRequired' => true,
        'min'        => ['amount' => 10000],
        'max'        => ['amount' => 250000],
    ]);

    expect($range->isRequired())->toBeTrue()
        ->and($range->allows(0))->toBeFalse()
        ->and($range->allows(100))->toBeTrue()
        ->and($range->lowestAccepted())->toBe(100);
});

it('reports whether an amount is inside the range', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    $range = InsuranceRange::fromOptionValue(['min' => ['amount' => 0], 'max' => ['amount' => 50000]]);

    expect($range->contains(500))->toBeTrue()
        ->and($range->contains(501))->toBeFalse();
});
