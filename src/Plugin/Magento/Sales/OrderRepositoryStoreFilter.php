<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Sales;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;

/**
 * Plugin restricting order queries to the stores visible to the token-authenticated caller.
 *
 * beforeGetList() injects a store_id IN (...) filter (or store_id IN (-1) when the caller
 * has zero permitted stores, so no row matches). afterGet() throws NoSuchEntityException
 * when a fetched order falls outside the permitted set. No-op when no token authenticated
 * the request — admin / Bearer / guest callers pass through untouched.
 */
class OrderRepositoryStoreFilter
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

    /**
     * @return array{0: SearchCriteriaInterface}
     */
    public function beforeGetList(
        OrderRepositoryInterface $subject,
        SearchCriteriaInterface  $searchCriteria
    ): array {
        $permitted = $this->tokenScopeContext->permittedStoreIds();
        // Non-token request (admin/Bearer/guest) — no store filter applied.
        if ($permitted === null) {
            return [$searchCriteria];
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

        $searchCriteria->setFilterGroups(
            array_merge($searchCriteria->getFilterGroups(), [$group])
        );

        return [$searchCriteria];
    }

    /**
     * @throws NoSuchEntityException When the order is outside the token-authenticated caller's scope.
     */
    public function afterGet(
        OrderRepositoryInterface $subject,
        OrderInterface           $order
    ): OrderInterface {
        $permitted = $this->tokenScopeContext->permittedStoreIds();
        // Non-token request (admin/Bearer/guest) — no store filter applied.
        if ($permitted === null) {
            return $order;
        }

        if (!in_array((int) $order->getStoreId(), $permitted, true)) {
            throw NoSuchEntityException::singleField('entity_id', (int) $order->getEntityId());
        }

        return $order;
    }
}
