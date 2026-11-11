<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Proxy;

/**
 * Describes an inbound proxy call with only the fields the security policy and the rejection log
 * need: method, upstream host key, acceptance flag and upstream path.
 */
final class ProxyRequest
{
    public string $method;
    public string $host;
    public bool $acceptance;
    public string $path;

    public function __construct(string $method, string $host, bool $acceptance, string $path)
    {
        $this->method = $method;
        $this->host = $host;
        $this->acceptance = $acceptance;
        $this->path = $path;
    }
}
