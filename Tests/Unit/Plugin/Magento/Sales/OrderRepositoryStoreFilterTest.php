<?php

declare(strict_types=1);

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Plugin\Magento\Sales\OrderRepositoryStoreFilter;
use MyParcelNL\Magento\Service\Authorization\StoreScopeSearchCriteria;

/**
 * beforeGetList behaviour is covered in StoreScopeSearchCriteriaTest; this only proves delegation.
 *
 * @param int[]|null $permitted
 */
function makeOrderRepositoryFilter(?array $permitted): array
{
    $scope = Mockery::mock(StoreScopeSearchCriteria::class);

    return [
        'plugin' => new OrderRepositoryStoreFilter(mockTokenScopeContext($permitted), $scope),
        'scope'  => $scope,
    ];
}

function mockOrder(int $storeId, int $entityId = 42): OrderInterface
{
    $order = Mockery::mock(OrderInterface::class);
    $order->shouldReceive('getStoreId')->andReturn($storeId);
    $order->shouldReceive('getEntityId')->andReturn($entityId);

    return $order;
}

it('beforeGetList hands the criteria to StoreScopeSearchCriteria and returns its result', function () {
    $bag = makeOrderRepositoryFilter([1, 3]);
    $in  = mockSearchCriteria();
    $out = mockSearchCriteria();

    $bag['scope']->shouldReceive('apply')->once()->with($in)->andReturn($out);

    expect($bag['plugin']->beforeGetList(Mockery::mock(OrderRepositoryInterface::class), $in))->toBe([$out]);
});

it('afterGet returns the order unchanged when no token authenticated this request', function () {
    $bag   = makeOrderRepositoryFilter(null);
    $order = Mockery::mock(OrderInterface::class);

    expect($bag['plugin']->afterGet(Mockery::mock(OrderRepositoryInterface::class), $order))->toBe($order);
});

it('afterGet returns the order unchanged when its store is in the permitted set', function () {
    $bag   = makeOrderRepositoryFilter([1, 2]);
    $order = mockOrder(2);

    expect($bag['plugin']->afterGet(Mockery::mock(OrderRepositoryInterface::class), $order))->toBe($order);
});

it('afterGet throws NoSuchEntityException when the order store is outside the permitted set', function () {
    $bag = makeOrderRepositoryFilter([2]);

    $bag['plugin']->afterGet(Mockery::mock(OrderRepositoryInterface::class), mockOrder(3, 99));
})->throws(NoSuchEntityException::class);
