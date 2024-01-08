<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Service;

use MyParcelNL\Magento\Contract\MagentoServiceInterface;
use MyParcelNL\Pdk\Facade\Pdk;

class MagentoService implements MagentoServiceInterface
{
    /**
     * @return string
     */
    public function getVersion(): string
    {
        return Pdk::get('magentoVersion');
    }
}
