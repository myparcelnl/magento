<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use MyParcelNL\Sdk\src\Model\Carrier\CarrierInstabox;

class DefaultDropOffPointInstabox extends AbstractDefaultDropOffPoint
{
    /**
     * @return int
     */
    public function getCarrierId(): int
    {
        return CarrierInstabox::ID;
    }
}

