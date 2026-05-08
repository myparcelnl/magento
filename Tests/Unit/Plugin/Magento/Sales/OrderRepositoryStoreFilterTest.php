<?php

declare(strict_types=1);

use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;
use MyParcelNL\Magento\Plugin\Magento\Sales\OrderRepositoryStoreFilter;

/**
 * Constructs the plugin with a TokenScopeContext mock that returns the given set,
 * plus stub builders that capture the produced Filter for assertion.
 *
 * @return array{plugin: OrderRepositoryStoreFilter, captured: array}
 */
function makeStoreFilter(?array $permitted): array
{
    $context = Mockery::mock(TokenScopeContext::class);
    $context->shouldReceive('permittedStoreIds')->andReturn($permitted);

    $captured = [
        'field'         => null,
        'conditionType' => null,
        'value'         => null,
    ];

    $filter        = Mockery::mock(Filter::class);
    $filterBuilder = Mockery::mock(FilterBuilder::class);
    $filterBuilder->shouldReceive('setField')->andReturnUsing(function ($f) use (&$captured, $filterBuilder) {
        $captured['field'] = $f;
        return $filterBuilder;
    });
    $filterBuilder->shouldReceive('setConditionType')->andReturnUsing(function ($c) use (&$captured, $filterBuilder) {
        $captured['conditionType'] = $c;
        return $filterBuilder;
    });
    $filterBuilder->shouldReceive('setValue')->andReturnUsing(function ($v) use (&$captured, $filterBuilder) {
        $captured['value'] = $v;
        return $filterBuilder;
    });
    $filterBuilder->shouldReceive('create')->andReturn($filter);

    $group              = Mockery::mock(FilterGroup::class);
    $filterGroupBuilder = Mockery::mock(FilterGroupBuilder::class);
    $filterGroupBuilder->shouldReceive('addFilter')->with($filter)->andReturnSelf();
    $filterGroupBuilder->shouldReceive('create')->andReturn($group);

    return [
        'plugin'   => new OrderRepositoryStoreFilter($context, $filterBuilder, $filterGroupBuilder),
        'captured' => &$captured,
        'group'    => $group,
    ];
}

function makeSearchCriteria(array $existingGroups = []): SearchCriteriaInterface
{
    $criteria = Mockery::mock(SearchCriteriaInterface::class);
    $criteria->shouldReceive('getFilterGroups')->andReturn($existingGroups);
    return $criteria;
}

it('beforeGetList is a no-op when no token authenticated this request', function () {
    $bag = makeStoreFilter(null);

    $criteria = Mockery::mock(SearchCriteriaInterface::class);
    $criteria->shouldNotReceive('setFilterGroups');
    $criteria->shouldNotReceive('getFilterGroups');

    $repository = Mockery::mock(OrderRepositoryInterface::class);
    [$result] = $bag['plugin']->beforeGetList($repository, $criteria);

    expect($result)->toBe($criteria);
});

it('beforeGetList appends a store_id IN(...) filter group preserving existing groups', function () {
    $existing = [Mockery::mock(FilterGroup::class)];
    $bag      = makeStoreFilter([1, 3]);
    $criteria = makeSearchCriteria($existing);

    $criteria->shouldReceive('setFilterGroups')
        ->once()
        ->withArgs(function ($groups) use ($existing, $bag) {
            return is_array($groups)
                && count($groups) === 2
                && $groups[0] === $existing[0]
                && $groups[1] === $bag['group'];
        });

    $repository = Mockery::mock(OrderRepositoryInterface::class);
    $bag['plugin']->beforeGetList($repository, $criteria);

    expect($bag['captured']['field'])->toBe('store_id');
    expect($bag['captured']['conditionType'])->toBe('in');
    expect($bag['captured']['value'])->toBe('1,3');
});

it('beforeGetList substitutes -1 when permitted set is empty so no rows match', function () {
    $bag      = makeStoreFilter([]);
    $criteria = makeSearchCriteria();
    $criteria->shouldReceive('setFilterGroups')->andReturnSelf();

    $repository = Mockery::mock(OrderRepositoryInterface::class);
    $bag['plugin']->beforeGetList($repository, $criteria);

    expect($bag['captured']['value'])->toBe('-1');
});

it('afterGet returns the order unchanged when no token authenticated this request', function () {
    $bag        = makeStoreFilter(null);
    $repository = Mockery::mock(OrderRepositoryInterface::class);
    $order      = Mockery::mock(OrderInterface::class);

    $result = $bag['plugin']->afterGet($repository, $order);

    expect($result)->toBe($order);
});

it('afterGet returns the order unchanged when its store is in the permitted set', function () {
    $bag        = makeStoreFilter([1, 2]);
    $repository = Mockery::mock(OrderRepositoryInterface::class);
    $order      = Mockery::mock(OrderInterface::class);
    $order->shouldReceive('getStoreId')->andReturn(2);
    $order->shouldReceive('getEntityId')->andReturn(42);

    $result = $bag['plugin']->afterGet($repository, $order);

    expect($result)->toBe($order);
});

it('afterGet throws NoSuchEntityException when the order store is outside the permitted set', function () {
    $bag        = makeStoreFilter([2]);
    $repository = Mockery::mock(OrderRepositoryInterface::class);
    $order      = Mockery::mock(OrderInterface::class);
    $order->shouldReceive('getStoreId')->andReturn(3);
    $order->shouldReceive('getEntityId')->andReturn(99);

    $bag['plugin']->afterGet($repository, $order);
})->throws(NoSuchEntityException::class);
