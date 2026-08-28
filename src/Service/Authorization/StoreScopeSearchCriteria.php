<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Authorization;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;

/**
 * Narrows a SearchCriteria to the stores a token-authenticated caller may see.
 *
 * The constraint gets its own filter group: filters inside a group are OR-ed, groups are AND-ed.
 */
class StoreScopeSearchCriteria
{
    private TokenScopeContext  $tokenScopeContext;
    private FilterBuilder      $filterBuilder;
    private FilterGroupBuilder $filterGroupBuilder;

    public function __construct(
        TokenScopeContext  $tokenScopeContext,
        FilterBuilder      $filterBuilder,
        FilterGroupBuilder $filterGroupBuilder
    ) {
        $this->tokenScopeContext  = $tokenScopeContext;
        $this->filterBuilder      = $filterBuilder;
        $this->filterGroupBuilder = $filterGroupBuilder;
    }

    public function apply(SearchCriteriaInterface $searchCriteria): SearchCriteriaInterface
    {
        $permitted = $this->tokenScopeContext->permittedStoreIds();
        // Non-token request (admin/Bearer/guest) — no store filter applied.
        if ($permitted === null) {
            return $searchCriteria;
        }

        // Empty permitted set: force store_id IN (-1) so no row matches (store_ids are positive).
        $values = $permitted === [] ? [-1] : $permitted;

        $filter = $this->filterBuilder
            ->setField('store_id')
            ->setConditionType('in')
            ->setValue($values)
            ->create();

        $group = $this->filterGroupBuilder
            ->addFilter($filter)
            ->create();

        // getFilterGroups() may return null per the interface docblock.
        $searchCriteria->setFilterGroups(
            array_merge((array) $searchCriteria->getFilterGroups(), [$group])
        );

        return $searchCriteria;
    }
}
