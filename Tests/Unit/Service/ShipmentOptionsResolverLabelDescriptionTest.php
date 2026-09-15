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
