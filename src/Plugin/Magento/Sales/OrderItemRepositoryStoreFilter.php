<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Sales;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;
use MyParcelNL\Magento\Service\Authorization\StoreScopeSearchCriteria;

/**
 * Plugin restricting order-item queries to the stores visible to the token-authenticated caller.
 */
class OrderItemRepositoryStoreFilter
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
        OrderItemRepositoryInterface $subject,
        SearchCriteriaInterface      $searchCriteria
    ): array {
        return [$this->storeScopeSearchCriteria->apply($searchCriteria)];
    }

    /**
     * @throws NoSuchEntityException When the item is outside the token-authenticated caller's scope.
     */
    public function afterGet(
        OrderItemRepositoryInterface $subject,
        OrderItemInterface           $item
    ): OrderItemInterface {
        $permitted = $this->tokenScopeContext->permittedStoreIds();
        // Non-token request (admin/Bearer/guest) — no store filter applied.
        if ($permitted === null) {
            return $item;
        }

        if (!in_array((int) $item->getStoreId(), $permitted, true)) {
            throw NoSuchEntityException::singleField('entity_id', (int) $item->getItemId());
        }

        return $item;
    }
}
