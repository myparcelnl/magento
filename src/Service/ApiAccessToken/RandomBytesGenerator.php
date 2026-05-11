<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ApiAccessToken;

class RandomBytesGenerator implements RandomBytesGeneratorInterface
{
    public function generate(int $length = 32): string
    {
        return random_bytes($length);
    }
}
