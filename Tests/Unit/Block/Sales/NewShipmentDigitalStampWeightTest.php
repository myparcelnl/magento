<?php

declare(strict_types=1);

use MyParcelNL\Magento\Block\Sales\NewShipment;
use MyParcelNL\Magento\Model\Shipment\DigitalStampWeight;
use MyParcelNL\Magento\Model\Source\DigitalStampWeightOptions;
use MyParcelNL\Magento\Service\Config;

/*
 * The weight buckets the New Shipment form offers, and the admin setting that must offer the
 * same ones. They held separate lists until 2026-08, so the last case here is the one that keeps
 * DigitalStampWeightOptions and the block in step.
 */

/** @param int $orderWeightGrams what getDigitalStampWeight() resolves to */
function createNewShipmentBlockWeighing(int $orderWeightGrams): NewShipment
{
    $block = newInstanceWithoutConstructor(NewShipment::class);

    $weight = Mockery::mock(\MyParcelNL\Magento\Service\Weight::class);
    $weight->shouldReceive('convertToGrams')->andReturn($orderWeightGrams);

    // A zero order weight falls through to the configured default, so pin that at zero too and the
    // weightless case stays genuinely weightless.
    $defaults = Mockery::mock(\MyParcelNL\Magento\Model\Source\DefaultOptions::class);
    $defaults->shouldReceive('getDigitalStampDefaultWeight')->andReturn(0);

    setPrivateProperty($block, 'order', createOrder(['getWeight' => (float) $orderWeightGrams]));
    setPrivateProperty($block, 'weightService', $weight);
    setPrivateProperty($block, 'defaultOptions', $defaults);

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
    // 90g and 300g both send 200: the merged 50-350 range sends a weight inside itself rather than
    // its own boundary. The old form-local list sent 100 and 350 here, values ReplaceDpzRange had
    // already retired from the matching admin setting.
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
    // They held separate lists until 2026-08, and the form's still carried the retired values.
    $setting = new DigitalStampWeightOptions(Mockery::mock(Config::class));

    expect(array_column($setting->toOptionArray(), 'value'))
        ->toBe(array_column(createNewShipmentBlockWeighing(0)->getDigitalStampWeightOptions(), 'value'));
});
