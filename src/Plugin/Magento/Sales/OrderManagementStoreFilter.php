<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Sales;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;

/**
 * Plugin gating per-order OrderManagement reads on the token-authenticated caller's store scope.
 *
 * The scope decision is delegated to the store-filtered {@see OrderRepositoryStoreFilter::afterGet},
 * which already throws NoSuchEntityException out of scope, so the boundary stays in one place.
 */
class OrderManagementStoreFilter
{
    private TokenScopeContext        $tokenScopeContext;
    private OrderRepositoryInterface $orderRepository;

    public function __construct(
        TokenScopeContext        $tokenScopeContext,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->tokenScopeContext = $tokenScopeContext;
        $this->orderRepository   = $orderRepository;
    }

    /**
     * @param int|string $id
     *
     * @throws NoSuchEntityException When the order is outside the token-authenticated caller's scope.
     */
    public function beforeGetCommentsList(OrderManagementInterface $subject, $id): ?array
    {
        $this->assertOrderInScope($id);

        return null;
    }

    /**
     * Redundant today: getStatus() resolves the order through OrderRepositoryInterface anyway. That
     * is an implementation detail of Magento, not a contract, so the guard stays explicit.
     *
     * @param int|string $id
     *
     * @throws NoSuchEntityException When the order is outside the token-authenticated caller's scope.
     */
    public function beforeGetStatus(OrderManagementInterface $subject, $id): ?array
    {
        $this->assertOrderInScope($id);

        return null;
    }

    /**
     * @param int|string $id
     *
     * @throws NoSuchEntityException
     */
    private function assertOrderInScope($id): void
    {
        // Non-token request (admin/Bearer/guest) — no scope to enforce.
        if ($this->tokenScopeContext->permittedStoreIds() === null) {
            return;
        }

        $this->orderRepository->get((int) $id);
    }
}
