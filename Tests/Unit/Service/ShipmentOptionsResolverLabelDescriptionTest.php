<?php

declare(strict_types=1);

it('reads the first item of the shipment from memory, so an unsaved shipment names its own product', function () {
    // The order also has another item; the shipment ships only Widget.
    ['resolver' => $resolver, 'select' => $select] = createLabelDescriptionResolver(
        [['product_id' => 5, 'name' => 'Widget', 'qty' => '2.0000']],
        '%product_name% x %product_qty%',
        [['product_id' => 4, 'name' => 'Other', 'qty' => 1]]
    );

    expect($resolver->getLabelDescription())->toBe('Widget x 2')
        ->and($select->fetchAlls)->toBe(0);
});

it('reads the order items when there is no shipment, as on the PPS path', function () {
    ['resolver' => $resolver, 'select' => $select] = createLabelDescriptionResolver(
        null,
        '%product_name%',
        [['product_id' => 5, 'name' => 'Widget', 'qty' => 2]]
    );

    expect($resolver->getLabelDescription())->toBe('Widget')
        ->and($select->table)->toBe('sales_order_item')
        ->and($select->wheres)->toBe(['main_table.order_id=?' => 7])
        ->and($select->columns)->toBe(['qty' => 'main_table.qty_ordered']);
});

it('runs no query for a template that names no product', function () {
    ['resolver' => $resolver, 'select' => $select] = createLabelDescriptionResolver(
        null,
        'Order %order_nr%',
        [['product_id' => 5, 'name' => 'Widget', 'qty' => 2]]
    );

    expect($resolver->getLabelDescription())->toBe('Order 100000001')
        ->and($select->fetchAlls)->toBe(0);
});

it('reads one named row of the order, not every column of every item', function () {
    ['resolver' => $resolver, 'select' => $select] = createLabelDescriptionResolver(
        null,
        '%product_name%',
        [['product_id' => 5, 'name' => 'Widget', 'qty' => 2]]
    );

    $resolver->getLabelDescription();

    expect($select->from)->toBe(['product_id', 'name'])
        ->and($select->limit)->toBe(1)
        ->and($select->order)->toBe('main_table.entity_id ASC');
});

it('reads the row once, however often the description is asked for', function () {
    ['resolver' => $resolver, 'select' => $select] = createLabelDescriptionResolver(
        null,
        '%product_name%',
        [['product_id' => 5, 'name' => 'Widget', 'qty' => 2]]
    );

    $resolver->getLabelDescription();
    $resolver->getLabelDescription();

    expect($select->fetchAlls)->toBe(1);
});
