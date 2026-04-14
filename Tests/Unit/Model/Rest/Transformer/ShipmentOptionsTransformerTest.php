<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Rest\Transformer\ShipmentOptionsTransformer;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractShipmentOptionsAdapter;
use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\ShipmentOptions as OrderApiShipmentOptions;

function shipmentOptionsMock(array $overrides = []): AbstractShipmentOptionsAdapter
{
    $defaults = [
        'hasAgeCheck'        => false,
        'hasSignature'       => false,
        'hasOnlyRecipient'   => false,
        'hasLargeFormat'     => false,
        'isReturn'           => false,
        'hasHideSender'      => false,
        'isPriorityDelivery' => false,
        'hasReceiptCode'     => false,
        'isSameDayDelivery'  => false,
        'hasCollect'         => false,
        'getInsurance'       => null,
        'getLabelDescription' => null,
    ];

    $mock = Mockery::mock(AbstractShipmentOptionsAdapter::class);
    foreach (array_merge($defaults, $overrides) as $method => $value) {
        $mock->shouldReceive($method)->andReturn($value);
    }

    return $mock;
}

it('returns null for null input', function () {
    expect((new ShipmentOptionsTransformer())->transform(null))->toBeNull();
});

it('returns null when no options are set', function () {
    expect((new ShipmentOptionsTransformer())->transform(shipmentOptionsMock()))->toBeNull();
});

it('maps each boolean option to the Order API camelCase field from attributeMap', function () {
    $apiFields = OrderApiShipmentOptions::attributeMap();

    $cases = [
        'hasAgeCheck'        => $apiFields['requires_age_verification'],
        'hasSignature'       => $apiFields['requires_signature'],
        'hasOnlyRecipient'   => $apiFields['recipient_only_delivery'],
        'hasLargeFormat'     => $apiFields['oversized_package'],
        'isReturn'           => $apiFields['print_return_label_at_drop_off'],
        'hasHideSender'      => $apiFields['hide_sender'],
        'isPriorityDelivery' => $apiFields['priority_delivery'],
        'hasReceiptCode'     => $apiFields['requires_receipt_code'],
        'isSameDayDelivery'  => $apiFields['same_day_delivery'],
        'hasCollect'         => $apiFields['scheduled_collection'],
    ];

    foreach ($cases as $getter => $expectedField) {
        $result = (new ShipmentOptionsTransformer())->transform(shipmentOptionsMock([$getter => true]));

        expect($result)->toBeObject();
        expect(property_exists($result, $expectedField))
            ->toBeTrue("option {$getter}() did not produce {$expectedField} field");
        expect($result->$expectedField)->toEqual(new stdClass());
    }
});

it('formats insurance as a micro-amount EUR object', function () {
    $insuranceField = OrderApiShipmentOptions::attributeMap()['insurance'];
    $result = (new ShipmentOptionsTransformer())->transform(shipmentOptionsMock(['getInsurance' => 5]));

    expect($result->$insuranceField)->toEqual((object) ['amount' => 5_000_000, 'currency' => 'EUR']);
});

it('ignores insurance when zero or null', function () {
    expect((new ShipmentOptionsTransformer())->transform(shipmentOptionsMock(['getInsurance' => 0])))->toBeNull();
    expect((new ShipmentOptionsTransformer())->transform(shipmentOptionsMock(['getInsurance' => null])))->toBeNull();
});

it('formats label description as customLabelText object', function () {
    $labelField = OrderApiShipmentOptions::attributeMap()['custom_label_text'];
    $result = (new ShipmentOptionsTransformer())->transform(shipmentOptionsMock(['getLabelDescription' => 'PO-123']));

    expect($result->$labelField)->toEqual((object) ['text' => 'PO-123']);
});

it('ignores empty label description', function () {
    expect((new ShipmentOptionsTransformer())->transform(shipmentOptionsMock(['getLabelDescription' => ''])))->toBeNull();
});
