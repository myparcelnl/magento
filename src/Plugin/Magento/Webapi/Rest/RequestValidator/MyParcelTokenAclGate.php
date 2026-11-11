<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Webapi\Rest\RequestValidator;

use Magento\Framework\Webapi\Authorization;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;
use MyParcelNL\Magento\Service\ScopedResourceRegistry;

/**
 * Around plugin on Magento\Framework\Webapi\Authorization::isAllowed that enforces
 * deny-by-default for token-authenticated callers.
 *
 * ACL grants from etc/integration.xml only unlock resources also listed in
 * {@see ScopedResourceRegistry}; native ACL still applies on top — both must pass.
 * Non-token callers (admin session, native Bearer, guest) bypass the gate.
 */
class MyParcelTokenAclGate
{
    private TokenScopeContext      $tokenScopeContext;
    private ScopedResourceRegistry $registry;

    public function __construct(
        TokenScopeContext      $tokenScopeContext,
        ScopedResourceRegistry $registry
    ) {
        $this->tokenScopeContext = $tokenScopeContext;
        $this->registry          = $registry;
    }

    /**
     * Deny-by-default for token-authenticated callers: ACL grants from etc/integration.xml
     * only unlock resources that are also registered in ScopedResourceRegistry. Native ACL
     * still applies on top — both must pass.
     *
     * @param string|string[] $resources
     */
    public function aroundIsAllowed(
        Authorization $subject,
        callable $proceed,
        $resources,
        $userId = null
    ): bool {
        if ($this->tokenScopeContext->getOwner() === null) {
            return $proceed($resources, $userId);
        }

        foreach ((array) $resources as $resource) {
            if (!$this->registry->isCovered((string) $resource)) {
                return false;
            }
        }

        return $proceed($resources, $userId);
    }
}
