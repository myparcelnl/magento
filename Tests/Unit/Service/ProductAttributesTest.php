<?php

declare(strict_types=1);

/**
 * These cases pin the two things that matter to the callers: the map is keyed by public product id,
 * and a batch costs one load. The collection double itself lives in Tests/Helpers, which several
 * service tests build their attribute rows with.
 */
it('answers a column keyed by the public product id', function () {
    $attributes = productAttributesFor([
        7 => ['myparcel_classification' => '6109.10'],
        9 => ['myparcel_classification' => '0901'],
    ]);

    expect($attributes->column([7, 9], 'classification'))->toBe([7 => '6109.10', 9 => '0901']);
});

it('reads a whole batch in one load', function () {
    $attributes = productAttributesFor([
        7 => ['myparcel_classification' => '61'],
        9 => ['myparcel_classification' => '62'],
    ], $loads);

    $attributes->column([7, 9], 'classification');

    expect($loads)->toHaveCount(1)
        ->and($loads[0])->toBe([7, 9]);
});

it('never asks twice for a product it already loaded', function () {
    $attributes = productAttributesFor([7 => ['myparcel_age_check' => '1']], $loads);

    $attributes->warm([7]);
    $attributes->value(7, 'age_check');
    $attributes->column([7], 'age_check');

    expect($loads)->toHaveCount(1);
});

it('asks nothing at all for an empty batch', function () {
    $attributes = productAttributesFor([], $loads);

    expect($attributes->column([], 'classification'))->toBe([])
        ->and($loads)->toBeEmpty();
});

it('leaves a product with no value out of the map, which is the callers no opinion', function () {
    $attributes = productAttributesFor([
        7 => ['myparcel_age_check' => '1'],
        9 => [],
    ]);

    expect($attributes->column([7, 9], 'age_check'))->toBe([7 => '1']);
});

it('treats an empty string as a row that says nothing', function () {
    // The raw reader answered '' here, and every caller then had to test for it separately.
    $attributes = productAttributesFor([7 => ['myparcel_age_check' => '']]);

    expect($attributes->column([7], 'age_check'))->toBe([])
        ->and($attributes->value(7, 'age_check'))->toBeNull();
});

it('does not re-query a product the collection never returned', function () {
    // Deleted, or out of this store. Absent is an answer, not a miss to retry.
    $attributes = productAttributesFor([], $loads);

    $attributes->value(7, 'age_check');
    $attributes->value(7, 'age_check');

    expect($loads)->toHaveCount(1)
        ->and($attributes->value(7, 'age_check'))->toBeNull();
});
