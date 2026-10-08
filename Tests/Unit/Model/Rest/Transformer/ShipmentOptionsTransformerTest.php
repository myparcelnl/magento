<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Rest\Transformer\ShipmentOptionsTransformer;
use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\ShipmentOptions as OrderApiShipmentOptions;

it('returns null for null input', function () {
    expect((new ShipmentOptionsTransformer())->transform(null))->toBeNull();
});

it('returns null when no options are set', function () {
    expect((new ShipmentOptionsTransformer())->transform(shipmentOptionsFixture()))->toBeNull();
});

it('maps each boolean option to the Order API camelCase field from attributeMap', function () {
    $apiFields = OrderApiShipmentOptions::attributeMap();

    $cases = [
        'age_check'         => $apiFields['requires_age_verification'],
        'signature'         => $apiFields['requires_signature'],
        'only_recipient'    => $apiFields['recipient_only_delivery'],
        'large_format'      => $apiFields['oversized_package'],
        'return'            => $apiFields['return_on_first_failed_delivery'],
        'hide_sender'       => $apiFields['hide_sender'],
        'priority_delivery' => $apiFields['priority_delivery'],
        'receipt_code'      => $apiFields['requires_receipt_code'],
        'same_day_delivery' => $apiFields['same_day_delivery'],
        'collect'           => $apiFields['scheduled_collection'],
        'fresh_food'        => $apiFields['fresh_food'],
        'frozen'            => $apiFields['frozen'],
    ];

    foreach ($cases as $option => $expectedField) {
        $result = (new ShipmentOptionsTransformer())->transform(shipmentOptionsFixture([$option => true]));

        expect($result)->toBeObject();
        expect(property_exists($result, $expectedField))
            ->toBeTrue("option {$option} did not produce {$expectedField} field");
        expect($result->$expectedField)->toEqual(new stdClass());
    }
});

it('formats insurance as a micro-amount EUR object', function () {
    $insuranceField = OrderApiShipmentOptions::attributeMap()['insurance'];
    $result = (new ShipmentOptionsTransformer())->transform(shipmentOptionsFixture(['insurance' => 5]));

    expect($result->$insuranceField)->toEqual((object) ['amount' => 5_000_000, 'currency' => 'EUR']);
});

it('ignores insurance when zero or null', function () {
    expect((new ShipmentOptionsTransformer())->transform(shipmentOptionsFixture(['insurance' => 0])))->toBeNull();
    expect((new ShipmentOptionsTransformer())->transform(shipmentOptionsFixture(['insurance' => null])))->toBeNull();
});

it('formats label description as customLabelText object', function () {
    $labelField = OrderApiShipmentOptions::attributeMap()['custom_label_text'];
    $result = (new ShipmentOptionsTransformer())->transform(shipmentOptionsFixture(['label_description' => 'PO-123']));

    expect($result->$labelField)->toEqual((object) ['text' => 'PO-123']);
});

it('ignores empty label description', function () {
    expect((new ShipmentOptionsTransformer())->transform(shipmentOptionsFixture(['label_description' => ''])))->toBeNull();
});

it('shows an option a merchant switched on, and leaves out one switched off or inheriting', function () {
    $stored = \MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions::fromCheckoutData([
        'deliveryType'    => 'standard',
        'shipmentOptions' => ['signature' => true, 'only_recipient' => false, 'age_check' => null],
    ])->getShipmentOptions();

    $result = (new ShipmentOptionsTransformer())->transform($stored);
    $fields = OrderApiShipmentOptions::attributeMap();

    expect(array_keys((array) $result))->toBe([$fields['requires_signature']]);
});
