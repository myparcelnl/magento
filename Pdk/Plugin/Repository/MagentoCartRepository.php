<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Pdk\Plugin\Repository;

use MyParcelNL\Pdk\App\Cart\Model\PdkCart;
use MyParcelNL\Pdk\App\Cart\Repository\AbstractPdkCartRepository;

class MagentoCartRepository extends AbstractPdkCartRepository
{
    public function get($input): PdkCart
    {
        // TODO: Implement get() method.
    }
}
