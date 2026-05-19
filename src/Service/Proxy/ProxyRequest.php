<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Proxy;

/**
 * Immutable descriptor of an inbound proxy call used for security-policy
 * checks and rejection logging. Holds only the fields the policy needs
 * (method, upstream host key, acceptance flag, upstream path) — not the
 * full request — so {@see Client::reject()} and {@see Client::isPathAllowed()}
 * can take one argument instead of four.
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
