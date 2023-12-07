<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Pdk\Service;

use MyParcelNL\Pdk\Base\Service\WeightService;
use MyParcelNL\Pdk\Facade\Pdk;

class MagentoWeightService extends WeightService
{
    /**
     * @param  int|float   $weight
     * @param  null|string $unit
     *
     * @return int
     */
    public function convertToGrams($weight, ?string $unit = null): int
    {
        return parent::convertToGrams(
            $weight,
            $unit ?: 'weight', Pdk::get('defaultWeightUnit')
        );
    }
}
