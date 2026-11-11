<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

/**
 * Allow-list of ACL resource ids that token-authenticated callers may reach.
 *
 * Populated via DI from etc/webapi_rest/di.xml and consulted by the
 * {@see \MyParcelNL\Magento\Plugin\Magento\Webapi\Rest\RequestValidator\MyParcelTokenAclGate}
 * to enforce deny-by-default on top of native Magento ACL — token callers see only what is
 * registered here, regardless of what etc/integration.xml grants them.
 */
class ScopedResourceRegistry
{
    /** @var array<string, true> */
    private array $resources;

    /**
     * @param string[] $resources ACL resource ids that token-authenticated callers may reach.
     */
    public function __construct(array $resources = [])
    {
        $this->resources = array_fill_keys(array_values($resources), true);
    }

    public function isCovered(string $aclResource): bool
    {
        return isset($this->resources[$aclResource]);
    }

    /**
     * @return string[]
     */
    public function all(): array
    {
        return array_keys($this->resources);
    }
}
