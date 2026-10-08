<?php

declare(strict_types=1);

/** What a merchant saved for the label wins over the configuration; nothing saved changes nothing. */
function storedLabelOptions(array $label): string
{
    return json_encode(['deliveryType' => 'standard'] + $label);
}

it('answers the saved label amount, or one', function () {
    expect(defaultOptionsFor([], 10.0, 'NL', 'NL', storedLabelOptions(['labelAmount' => 3]))->getLabelAmount())->toBe(3)
        ->and(defaultOptionsFor([], 10.0)->getLabelAmount())->toBe(1);
});

it('answers the saved digital stamp weight before the configured one', function () {
    expect(defaultOptionsFor([], 10.0, 'NL', 'NL', storedLabelOptions(['digitalStampWeight' => 350]))->getDigitalStampDefaultWeight())
        ->toBe(350);
});
