<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Api;

/**
 * Proxy interface for forwarding requests to MyParcel API
 */
interface ProxyInterface
{
    /**
     * Forward request to MyParcel API
     *
     * @param string $path
     * @return array
     */
    public function forward(string $path): array;
}
