<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Sales;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;
use MyParcelNL\Magento\Service\Authorization\StoreScopeSearchCriteria;

/**
 * Plugin restricting order queries to the stores visible to the token-authenticated caller.
 *
 * afterGet() throws NoSuchEntityException (404) rather than a 403, so the boundary does not reveal
 * that the order exists.
 */
class OrderRepositoryStoreFilter
{
    private TokenScopeContext        $tokenScopeContext;
    private StoreScopeSearchCriteria $storeScopeSearchCriteria;

    public function __construct(
        TokenScopeContext        $tokenScopeContext,
        StoreScopeSearchCriteria $storeScopeSearchCriteria
    ) {
        $this->tokenScopeContext        = $tokenScopeContext;
        $this->storeScopeSearchCriteria = $storeScopeSearchCriteria;
    }

    /**
     * @return array{0: SearchCriteriaInterface}
     */
    public function beforeGetList(
        OrderRepositoryInterface $subject,
        SearchCriteriaInterface  $searchCriteria
    ): array {
        return [$this->storeScopeSearchCriteria->apply($searchCriteria)];
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
