<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Contract;

interface MagentoServiceInterface
{
    public function getVersion(): string;
}
