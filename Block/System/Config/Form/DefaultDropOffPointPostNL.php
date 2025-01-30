<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

class DefaultDropOffPointPostNL extends AbstractDefaultDropOffPoint
{
    /**
     * @return int
     */
    public function getCarrierId(): int
    {
        return CarrierPostNL::ID;
    }
}

