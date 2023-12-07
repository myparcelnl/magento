<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Pdk\Plugin\Service;

use MyParcelNL\Pdk\App\Order\Contract\OrderStatusServiceInterface;

class MagentoStatusService implements OrderStatusServiceInterface
{
    public function all(): array
    {
        // TODO: Implement all() method.
    }

    public function updateStatus(array $orderIds, string $status): void
    {
        // TODO: Implement updateStatus() method.
    }
}
