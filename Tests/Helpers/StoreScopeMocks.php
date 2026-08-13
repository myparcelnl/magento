<?php

declare(strict_types=1);

use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteriaInterface;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;

/**
 * Fakes shared by the store-scope filtering tests.
 *
 * The filter-building rig lives in StoreScopeSearchCriteriaTest instead — that is the only test that
 * inspects the produced Filter; the plugin tests only need to prove they delegate.
 */

/**
 * @param int[]|null $permitted null = no token authenticated the request.
 */
function mockTokenScopeContext(?array $permitted): TokenScopeContext
{
    $context = Mockery::mock(TokenScopeContext::class);
    $context->shouldReceive('permittedStoreIds')->andReturn($permitted);

    return $context;
}

/**
 * @param FilterGroup[] $existingGroups
 */
function mockSearchCriteria(array $existingGroups = []): SearchCriteriaInterface
{
    $criteria = Mockery::mock(SearchCriteriaInterface::class);
    $criteria->shouldReceive('getFilterGroups')->andReturn($existingGroups);

    return $criteria;
}

/**
 * A SearchCriteria that fails the test if the store filter touches it at all — for the non-token
 * (admin / Bearer / guest) pass-through cases.
 */
function mockUntouchedSearchCriteria(): SearchCriteriaInterface
{
    $criteria = Mockery::mock(SearchCriteriaInterface::class);
    $criteria->shouldNotReceive('setFilterGroups');
    $criteria->shouldNotReceive('getFilterGroups');

    return $criteria;
}
