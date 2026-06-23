<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ApiAccessToken;

/**
 * Production implementation delegating to PHP's random_bytes().
 *
 * Exists as a thin seam so tests can inject deterministic byte streams via
 * {@see RandomBytesGeneratorInterface} without monkey-patching the global.
 */
class RandomBytesGenerator implements RandomBytesGeneratorInterface
{
    public function generate(int $length = 32): string
    {
        return random_bytes($length);
    }
}
