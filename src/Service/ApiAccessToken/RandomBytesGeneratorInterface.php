<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ApiAccessToken;

/**
 * Seam for cryptographically secure random byte generation.
 *
 * Implementations must return $length random bytes. The default length of 32 seeds the
 * 64-hex-character API access tokens emitted by {@see TokenService::generateForScope()}.
 */
interface RandomBytesGeneratorInterface
{
    public function generate(int $length = 32): string;
}
