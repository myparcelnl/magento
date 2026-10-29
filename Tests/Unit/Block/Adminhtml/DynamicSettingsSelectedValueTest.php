<?php

declare(strict_types=1);

use MyParcelNL\Magento\Block\Adminhtml\DynamicSettings;

const YESNO_OPTIONS = [['value' => 1, 'label' => 'Yes'], ['value' => 0, 'label' => 'No']];

function selectedValue($value, array $options = YESNO_OPTIONS): string
{
    return newInstanceWithoutConstructor(DynamicSettings::class)->getSelectedValue($value, $options);
}

it('shows No for a path with no value, so a save does not switch it on', function () {
    expect(selectedValue(null))->toBe('0');
});

it('shows a stored value as it is', function ($value, string $shown) {
    expect(selectedValue($value))->toBe($shown);
})->with([
    'on'           => ['1', '1'],
    'off'          => ['0', '0'],
    'an int'       => [1, '1'],
    'empty string' => ['', ''],
]);

it('finds a 0 inside an option group', function () {
    expect(selectedValue(null, [['label' => 'Group', 'value' => [['value' => '5'], ['value' => '0']]]]))->toBe('0');
});

it('leaves a select without a 0 option to its empty option, or to the browser', function () {
    expect(selectedValue(null, [['value' => ''], ['value' => '0']]))->toBe('')
        ->and(selectedValue(null, [['value' => 'price'], ['value' => 'No']]))->toBe('');
});
