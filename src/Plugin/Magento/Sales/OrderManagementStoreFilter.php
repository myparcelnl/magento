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
 * Covers GET /V1/orders/:id/comments and /V1/orders/:id/statuses, which Magento_Sales::actions_view
 * authorizes alongside the order routes.
 *
 * The scope decision is delegated to the store-filtered OrderRepositoryInterface rather than
 * re-derived: {@see OrderRepositoryStoreFilter::afterGet} already throws NoSuchEntityException for an
 * out-of-scope order, so the boundary stays in one place and both routes answer 404 identically.
 * Non-token callers short-circuit before that load, so admin and Bearer requests pay no extra query.
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
     * Redundant *today*: getStatus() happens to resolve the order through OrderRepositoryInterface,
     * so it already 404s out of scope — which is why the security review did not list /statuses as
     * vulnerable. That is an internal implementation detail of Magento's OrderManagement, not a
     * contract, so the guard stays explicit rather than relying on it surviving an upgrade.
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
        // Non-token request (admin/Bearer/guest): skip the load entirely, no scope to enforce.
        if ($this->tokenScopeContext->permittedStoreIds() === null) {
            return;
        }

        $this->orderRepository->get((int) $id);
    }
}
