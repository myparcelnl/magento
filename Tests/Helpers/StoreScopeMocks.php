<?php

declare(strict_types=1);

use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteriaInterface;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;

// Fakes shared by the store-scope filtering tests.

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

function mockUntouchedSearchCriteria(): SearchCriteriaInterface
{
    $criteria = Mockery::mock(SearchCriteriaInterface::class);
    $criteria->shouldNotReceive('setFilterGroups');
    $criteria->shouldNotReceive('getFilterGroups');

    return $criteria;
}
