<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Rest\Transformer\CarrierTransformer;
use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\Carrier as OrderApiCarrier;

it('returns null for null input', function () {
    expect((new CarrierTransformer())->transform(null))->toBeNull();
});

it('returns an Order API enum value unchanged', function () {
    expect((new CarrierTransformer())->transform(OrderApiCarrier::POSTNL))
        ->toBe(OrderApiCarrier::POSTNL);
});

it('converts lowercase input to the matching Order API enum via snake_case', function () {
    expect((new CarrierTransformer())->transform('postnl'))->toBe(OrderApiCarrier::POSTNL);
    expect((new CarrierTransformer())->transform('cheap_cargo'))->toBe(OrderApiCarrier::CHEAP_CARGO);
});

it('maps legacy Magento carrier names to the Order API enum', function () {
    $t = new CarrierTransformer();

    expect($t->transform('bol.com'))->toBe(OrderApiCarrier::BOL);
    expect($t->transform('bol_com'))->toBe(OrderApiCarrier::BOL);
    expect($t->transform('dhlforyou'))->toBe(OrderApiCarrier::DHL_FOR_YOU);
    expect($t->transform('dhlparcelconnect'))->toBe(OrderApiCarrier::DHL_PARCEL_CONNECT);
    expect($t->transform('dhleuroplus'))->toBe(OrderApiCarrier::DHL_EUROPLUS);
    expect($t->transform('ups'))->toBe(OrderApiCarrier::UPS_STANDARD);
});

it('returns null for unknown carriers', function () {
    expect((new CarrierTransformer())->transform('does_not_exist'))->toBeNull();
});
