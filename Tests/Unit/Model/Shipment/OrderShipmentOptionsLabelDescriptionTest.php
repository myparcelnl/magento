<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Model\Shipment\OrderShipmentOptions;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Model\Shipment\PackageType;

/**
 * The API refuses a label description over 45 characters, and the generated setter throws rather
 * than truncating. Every case here goes through that real setter, so a length the API would refuse
 * fails the test instead of passing quietly.
 *
 * Nothing asserted any of this before, which is how a cap three characters too low survived
 *.
 */
function labelDescriptionFor(string $description): string
{
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn(PackageType::PACKAGE);

    $subject = createOrderShipmentOptions([
        'options'         => [],
        'defaultOptions'  => $defaultOptions,
        'deliveryOptions' => DeliveryOptions::fromOrderFallback([]),
        'resolved'        => ResolvedOptions::resolved(['label_description' => $description]),
    ]);

    return (string) $subject->shipmentOptions(createAddress(['getCountryId' => 'DE']))->getLabelDescription();
}

it('leaves a description that already fits exactly alone', function () {
    $exact = str_repeat('a', OrderShipmentOptions::LABEL_DESCRIPTION_MAX_LENGTH);

    expect(labelDescriptionFor($exact))->toBe($exact);
});

it('uses all 45 characters, where the consignment stopped three short', function () {
    // AbstractConsignment did Str::limit($x, 45 - 3), but Str::limit counts its own marker inside
    // the width, so the subtraction happened twice and three characters were never used.
    $result = labelDescriptionFor(str_repeat('a', 200));

    expect(mb_strlen($result))->toBe(OrderShipmentOptions::LABEL_DESCRIPTION_MAX_LENGTH);
});

it('says it truncated rather than cutting silently', function () {
    expect(labelDescriptionFor(str_repeat('a', 200)))->toEndWith('...');
});

it('does not truncate one character early', function () {
    // 46 in, so exactly one character too many: the ellipsis costs three of the 45, it does not
    // extend past them.
    $result = labelDescriptionFor(str_repeat('a', 46));

    expect($result)->toBe(str_repeat('a', 42) . '...');
});
