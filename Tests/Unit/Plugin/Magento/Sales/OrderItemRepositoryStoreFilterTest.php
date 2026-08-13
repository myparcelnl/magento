<?php

declare(strict_types=1);

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use MyParcelNL\Magento\Plugin\Magento\Sales\OrderItemRepositoryStoreFilter;
use MyParcelNL\Magento\Service\Authorization\StoreScopeSearchCriteria;

/**
 * @param int[]|null $permitted
 */
function makeOrderItemRepositoryFilter(?array $permitted): array
{
    $scope = Mockery::mock(StoreScopeSearchCriteria::class);

    return [
        'plugin' => new OrderItemRepositoryStoreFilter(mockTokenScopeContext($permitted), $scope),
        'scope'  => $scope,
    ];
}

/**
 * @param int|null $storeId null models the nullable sales_order_item.store_id column.
 */
function mockOrderItem(?int $storeId, int $itemId = 7): OrderItemInterface
{
    $item = Mockery::mock(OrderItemInterface::class);
    $item->shouldReceive('getStoreId')->andReturn($storeId);
    $item->shouldReceive('getItemId')->andReturn($itemId);

    return $item;
}

it('beforeGetList hands the criteria to StoreScopeSearchCriteria and returns its result', function () {
    $bag = makeOrderItemRepositoryFilter([1, 3]);
    $in  = mockSearchCriteria();
    $out = mockSearchCriteria();

    $bag['scope']->shouldReceive('apply')->once()->with($in)->andReturn($out);

    expect($bag['plugin']->beforeGetList(Mockery::mock(OrderItemRepositoryInterface::class), $in))->toBe([$out]);
});

it('afterGet returns the item unchanged when no token authenticated this request', function () {
    $bag  = makeOrderItemRepositoryFilter(null);
    $item = mockOrderItem(1);

    expect($bag['plugin']->afterGet(Mockery::mock(OrderItemRepositoryInterface::class), $item))->toBe($item);
});

it('afterGet returns the item unchanged when its store is in the permitted set', function () {
    $bag  = makeOrderItemRepositoryFilter([1, 3]);
    $item = mockOrderItem(3);

    expect($bag['plugin']->afterGet(Mockery::mock(OrderItemRepositoryInterface::class), $item))->toBe($item);
});

it('afterGet throws NoSuchEntityException for an item outside the permitted set', function () {
    $bag = makeOrderItemRepositoryFilter([3]);

    $bag['plugin']->afterGet(Mockery::mock(OrderItemRepositoryInterface::class), mockOrderItem(1));
})->throws(NoSuchEntityException::class);

it('afterGet fails closed for a null store_id', function (?array $permitted) {
    $bag = makeOrderItemRepositoryFilter($permitted);

    $bag['plugin']->afterGet(Mockery::mock(OrderItemRepositoryInterface::class), mockOrderItem(null));
})->with([[[1, 3]], [[]]])->throws(NoSuchEntityException::class);
