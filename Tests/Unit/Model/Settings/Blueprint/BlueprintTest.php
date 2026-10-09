<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Settings\Blueprint\Blueprint;
use MyParcelNL\Magento\Model\Settings\Blueprint\Field;
use MyParcelNL\Magento\Model\Settings\Blueprint\Group;
use MyParcelNL\Magento\Model\Settings\Blueprint\Section;

it('serialises a field to exactly the keys the template reads', function () {
    $active = Field::select('carrier/delivery/active', 'Delivery enabled', 'Yesno');
    $fee    = Field::text('carrier/delivery/fee', 'Fee')
        ->withTooltip('Added to the price')
        ->withValidate('validate-number')
        ->dependsOn($active);

    expect($fee->toArray())->toBe([
        'id'            => 'fee',
        'path'          => 'carrier/delivery/fee',
        'type'          => 'text',
        'label'         => 'Fee',
        'showInDefault' => true,
        'showInWebsite' => true,
        'showInStore'   => true,
        'tooltip'       => 'Added to the price',
        'validate'      => 'validate-number',
        'depends'       => [['field' => 'carrier/delivery/active', 'value' => '1']],
    ]);
});

it('resolves a dependency to the path of the field it points at', function () {
    $mode  = Field::select('carrier/default_options/large_format_active', 'Large format', 'Options');
    $price = Field::text('carrier/default_options/large_format_from_price', 'From price')->dependsOn($mode, 'price');

    expect($price->toArray()['depends'])->toBe([
        ['field' => $mode->path(), 'value' => 'price'],
    ]);
});

it('leaves the field it was built from unchanged', function () {
    $field = Field::text('a/b/c', 'C');

    $field->withTooltip('tip')->asDisabled()->inDefaultScopeOnly();

    expect($field->toArray())->not->toHaveKeys(['tooltip', 'disabled'])
        ->and($field->toArray()['showInStore'])->toBeTrue();
});

it('marks a disabled field and a default-only field', function () {
    $field = Field::select('a/b/active', 'Active', 'Yesno')->asDisabled()->inDefaultScopeOnly()->toArray();

    expect($field['disabled'])->toBeTrue()
        ->and([$field['showInDefault'], $field['showInWebsite'], $field['showInStore']])->toBe([true, false, false]);
});

it('returns the sections shape and every path', function () {
    $blueprint = new Blueprint([
        new Section('general', 'General settings', [
            new Group('api', 'API settings', [Field::text('general/api/key', 'API key')], 'A note'),
            new Group('empty', 'Empty', []),
        ]),
        new Section('carrier', 'Carrier settings', [
            new Group('delivery', 'Delivery settings', [Field::select('carrier/delivery/active', 'On', 'Yesno')]),
        ]),
    ]);

    $sections = $blueprint->toArray()['sections'];

    expect(array_column($sections, 'id'))->toBe(['general', 'carrier'])
        ->and(array_column($sections[0]['groups'], 'id'))->toBe(['api'])
        ->and($sections[0]['groups'][0]['comment'])->toBe('A note')
        ->and($blueprint->paths())->toBe(['general/api/key', 'carrier/delivery/active'])
        ->and($blueprint->isPermissive())->toBeFalse();
});

it('drops a default-only field at website and store scope, and a group it leaves empty', function () {
    $blueprint = new Blueprint([
        new Section('general', 'General', [
            new Group('print', 'Print', [
                Field::text('general/print/paper_type', 'Paper type'),
                Field::text('general/print/weight_indication', 'Weight type')->inDefaultScopeOnly(),
            ]),
            new Group('api', 'Api', [
                Field::text('general/api/key', 'API key')->inDefaultScopeOnly(),
            ]),
        ]),
    ]);

    expect($blueprint->shownAt('default')->paths())
        ->toBe(['general/print/paper_type', 'general/print/weight_indication', 'general/api/key'])
        ->and($blueprint->shownAt('websites')->paths())->toBe(['general/print/paper_type'])
        ->and($blueprint->shownAt('stores')->toArray()['sections'][0]['groups'])->toHaveCount(1);
});

it('keeps the default out of the form array', function () {
    $field = Field::select('carrier/delivery/active', 'Delivery enabled', 'Yesno')->withDefault('0');

    expect($field->default())->toBe('0')
        ->and($field->toArray())->not->toHaveKey('default');
});

it('lists the default of every field that has one', function () {
    $blueprint = new Blueprint([
        new Section('carrier', 'Carrier', [
            new Group('delivery', 'Delivery', [
                Field::select('carrier/delivery/active', 'Delivery enabled', 'Yesno')->withDefault('0'),
                Field::text('carrier/delivery/title', 'Title'),
            ]),
        ]),
    ]);

    expect($blueprint->defaults())->toBe(['carrier/delivery/active' => '0']);
});
