<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Hooks\Contract;

interface MagentoHooksInterface
{
    /**
     * Register the necessary actions and filters.
     *
     * @param  array $data
     *
     * @return void
     */
    public function apply(array $data): void;
}
