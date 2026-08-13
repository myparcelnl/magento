<?php

declare(strict_types=1);

use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use MyParcelNL\Magento\Service\Authorization\StoreScopeSearchCriteria;

/**
 * Builds the subject over mocked builders, capturing the Filter it produces.
 *
 * This is the only place the produced Filter is inspected — the plugin tests assert delegation only,
 * so the capture rig lives here rather than in Tests/Helpers.
 *
 * @param int[]|null $permitted
 *
 * @return array{subject: StoreScopeSearchCriteria, captured: array, group: FilterGroup}
 */
function makeStoreScopeSearchCriteria(?array $permitted): array
{
    $captured = ['field' => null, 'conditionType' => null, 'value' => null];

    $filter        = Mockery::mock(Filter::class);
    $filterBuilder = Mockery::mock(FilterBuilder::class);
    foreach (['setField' => 'field', 'setConditionType' => 'conditionType', 'setValue' => 'value'] as $method => $key) {
        $filterBuilder->shouldReceive($method)->andReturnUsing(
            function ($value) use (&$captured, $filterBuilder, $key) {
                $captured[$key] = $value;
                return $filterBuilder;
            }
        );
    }
    $filterBuilder->shouldReceive('create')->andReturn($filter);

    $group              = Mockery::mock(FilterGroup::class);
    $filterGroupBuilder = Mockery::mock(FilterGroupBuilder::class);
    $filterGroupBuilder->shouldReceive('addFilter')->with($filter)->andReturnSelf();
    $filterGroupBuilder->shouldReceive('create')->andReturn($group);

    return [
        'subject'  => new StoreScopeSearchCriteria(
            mockTokenScopeContext($permitted),
            $filterBuilder,
            $filterGroupBuilder
        ),
        'captured' => &$captured,
        'group'    => $group,
    ];
}

it('leaves the criteria untouched when no token authenticated the request', function () {
    $bag      = makeStoreScopeSearchCriteria(null);
    $criteria = mockUntouchedSearchCriteria();

    expect($bag['subject']->apply($criteria))->toBe($criteria);
});

it('appends a store_id IN(...) group preserving existing groups', function () {
    $existing = [Mockery::mock(FilterGroup::class)];
    $bag      = makeStoreScopeSearchCriteria([1, 3]);
    $criteria = mockSearchCriteria($existing);

    $criteria->shouldReceive('setFilterGroups')
        ->once()
        ->withArgs(fn ($groups) => $groups === [$existing[0], $bag['group']]);

    expect($bag['subject']->apply($criteria))->toBe($criteria);
    expect($bag['captured'])->toBe(['field' => 'store_id', 'conditionType' => 'in', 'value' => [1, 3]]);
});

it('substitutes -1 when the permitted set is empty so no rows match', function () {
    $bag      = makeStoreScopeSearchCriteria([]);
    $criteria = mockSearchCriteria();
    $criteria->shouldReceive('setFilterGroups')->andReturnSelf();

    $bag['subject']->apply($criteria);

    expect($bag['captured']['value'])->toBe([-1]);
});

it('tolerates a null return from getFilterGroups, which the interface permits', function () {
    $bag      = makeStoreScopeSearchCriteria([2]);
    $criteria = Mockery::mock(SearchCriteriaInterface::class);
    $criteria->shouldReceive('getFilterGroups')->andReturnNull();
    $criteria->shouldReceive('setFilterGroups')
        ->once()
        ->withArgs(fn ($groups) => $groups === [$bag['group']]);

    expect($bag['subject']->apply($criteria))->toBe($criteria);
});
