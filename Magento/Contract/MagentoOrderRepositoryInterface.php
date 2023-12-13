<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Magento\Contract;

use Magento\Sales\Model\Order;
use MyParcelNL\Pdk\Base\Support\Collection;

interface MagentoOrderRepositoryInterface
{
    /**
     * @param  int|string|\Magento\Sales\Model\Order $input
     *
     * @return Order
     */
    public function get($input): Order;

    /**
     * @param  int|string|Order $input
     *
     * @return \MyParcelNL\Pdk\Base\Support\Collection<\Magento\Sales\Model\Order\Item>
     */
    public function getItems($input): Collection;
}
