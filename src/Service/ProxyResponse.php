<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

final class ProxyResponse
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
