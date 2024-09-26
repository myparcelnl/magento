<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Costs;

class DeliveryCostsService
{
    // todo: accept country, weight, packagetype, carrier, deliverytype etc. Or maybe a consignment then?
    public function getBasePrice(): float
    {
        return 5;
    }
    /**
     * @param  float $price
     *
     * @return int
     */
    public function getCentsByPrice(float $price): int
    {
        return (int)$price * 100;
    }
}
