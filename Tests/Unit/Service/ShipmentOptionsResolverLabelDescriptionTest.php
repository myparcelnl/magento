<?php

declare(strict_types=1);

it('reads shipment items by the shipment id, never the order id', function () {
    ['resolver' => $resolver, 'select' => $select] = createLabelDescriptionResolver(
        42,
        '%product_name%',
        [['product_id' => 5, 'name' => 'Widget', 'qty' => 2]]
    );

    expect($resolver->getLabelDescription())->toBe('Widget')
        ->and($select->table)->toBe('sales_shipment_item')
        ->and($select->wheres)->toBe(['main_table.parent_id=?' => 42]);
});

it('reads the order items when there is no shipment yet, as on the PPS path', function () {
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

/**
 * A label description is commonly configured and commonly names no product at all, and the query
 * ran anyway — once per built shipment, and once more per collo.
 */
it('runs no query for a template that names no product', function () {
    ['resolver' => $resolver, 'select' => $select] = createLabelDescriptionResolver(
        42,
        'Order %order_nr%',
        [['product_id' => 5, 'name' => 'Widget', 'qty' => 2]]
    );

    expect($resolver->getLabelDescription())->toBe('Order 100000001')
        ->and($select->fetchAlls)->toBe(0);
});

it('reads one named row, not every column of every item', function () {
    // Only row 0 is ever read, so the rest was fetched and thrown away — and without an order which
    // row that was is the database's choice.
    ['resolver' => $resolver, 'select' => $select] = createLabelDescriptionResolver(
        42,
        '%product_name%',
        [['product_id' => 5, 'name' => 'Widget', 'qty' => 2]]
    );

    $resolver->getLabelDescription();

    expect($select->from)->toBe(['product_id', 'name', 'qty'])
        ->and($select->limit)->toBe(1)
        ->and($select->order)->toBe('main_table.entity_id ASC');
});

it('reads the row once, however often the description is asked for', function () {
    ['resolver' => $resolver, 'select' => $select] = createLabelDescriptionResolver(
        42,
        '%product_name%',
        [['product_id' => 5, 'name' => 'Widget', 'qty' => 2]]
    );

    $resolver->getLabelDescription();
    $resolver->getLabelDescription();

    expect($select->fetchAlls)->toBe(1);
});
