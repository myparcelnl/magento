<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ApiAccessToken;

interface RandomBytesGeneratorInterface
{
    public function generate(int $length = 32): string;
}
