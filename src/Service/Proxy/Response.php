<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Proxy;

/**
 * Plain value object for an upstream proxy result (status, header map,
 * body). Decoupled from Magento types so {@see Client} can return the
 * same shape for upstream success, upstream failure, and locally-
 * generated proxy rejections.
 */
final class Response
{
    public int $status;

    /** @var array<string,string> */
    public array $headers;

    public string $body;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }
}
