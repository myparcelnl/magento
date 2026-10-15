<?php

declare(strict_types=1);

use MyParcelNL\Magento\Block\System\Config\Form\OrderManagementInfo;

it('names the order management the export decides on', function (?array $features, ?string $orderManagement) {
    expect(OrderManagementInfo::summary('live-key', $features))
        ->toBe(['api_key' => true, 'order_management' => $orderManagement, 'features' => $features]);
})->with([
    'order v1'             => [['LEGACY_ORDER_MANAGEMENT', 'ORDER_MANAGEMENT'], 'v1'],
    'order v2'             => [['ORDER_MANAGEMENT'], 'v2'],
    'none'                 => [['ORDER_NOTES'], 'none'],
    'imported before 5.11' => [null, null],
]);

it('says so when the scope has no api key', function () {
    expect(OrderManagementInfo::summary('', null))
        ->toBe(['api_key' => false, 'order_management' => null, 'features' => null]);
});
