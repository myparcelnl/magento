<?php

declare(strict_types=1);

use MyParcelNL\Magento\Block\Sales\NewShipment;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Source\DigitalStampWeightOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DigitalStampWeight;

/*
 * The weight buckets the New Shipment form offers, and the admin setting that must offer the
 * same ones.
 */

/** @param int $orderWeightGrams what getDigitalStampWeight() resolves to */
function createNewShipmentBlockWeighing(int $orderWeightGrams): NewShipment
{
    // The configured default weight stays at zero, so the weightless case stays weightless.
    $block = createNewShipmentBlockWith(CapabilitySet::permissive(), ['getWeight' => (float) $orderWeightGrams]);
    getPrivateProperty($block, 'weightService')->shouldReceive('convertToGrams')->andReturn($orderWeightGrams);

    return $block;
}

/** @return int[] values of the selected buckets */
function selectedWeights(NewShipment $block): array
{
    return array_column(
        array_filter($block->getDigitalStampWeightOptions(), static fn (array $o): bool => $o['selected']),
        'value'
    );
}

it('selects exactly one range, and the lightest one for a weightless order', function () {
    // 90g and 300g both send 200: the 50-350 range sends a weight inside itself, not its boundary.
    foreach ([0 => 20, 15 => 20, 20 => 20, 25 => 50, 90 => 200, 300 => 200, 350 => 200, 1500 => 2000] as $grams => $expected) {
        expect(selectedWeights(createNewShipmentBlockWeighing($grams)))
            ->toBe([$expected], "an order of {$grams}g should send {$expected}g");
    }
});

it('selects nothing above the heaviest range rather than guessing', function () {
    expect(selectedWeights(createNewShipmentBlockWeighing(5000)))->toBe([]);
});

it('offers the no-standard-weight option first and never selected', function () {
    $options = createNewShipmentBlockWeighing(25)->getDigitalStampWeightOptions();

    expect($options[0]['value'])->toBe(DigitalStampWeight::NO_STANDARD_WEIGHT)
        ->and($options[0]['selected'])->toBeFalse()
        ->and(array_column($options, 'value'))->toBe([0, 20, 50, 200, 2000])
        ->and(array_column($options, 'value'))->not->toContain(100)
        ->and(array_column($options, 'value'))->not->toContain(350);
});

it('offers the admin setting and the form the identical set of weights', function () {
    $setting = new DigitalStampWeightOptions(Mockery::mock(Config::class), new DigitalStampWeight());

    expect(array_column($setting->toOptionArray(), 'value'))
        ->toBe(array_column(createNewShipmentBlockWeighing(0)->getDigitalStampWeightOptions(), 'value'));
});
