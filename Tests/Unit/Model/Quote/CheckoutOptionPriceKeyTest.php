<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

it('names each option fee the way the widget reads it', function (string $option, string $priceKey) {
    expect(invokePrivateMethod(newInstanceWithoutConstructor(Checkout::class), 'getPriceKeyFromOption', [$option]))->toBe($priceKey);
})->with([
    [ShipmentOption::SIGNATURE, 'priceSignature'],
    [ShipmentOption::RECEIPT_CODE, 'priceReceiptCode'],
    [ShipmentOption::ONLY_RECIPIENT, 'priceOnlyRecipient'],
    [ShipmentOption::PRIORITY_DELIVERY, 'pricePriorityDelivery'],
]);
