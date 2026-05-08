<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

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
