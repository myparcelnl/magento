<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use MyParcelNL\Pdk\Base\Contract\CronServiceInterface;

class MagentoCronService implements CronServiceInterface
{
    public function dispatch($callback, ...$args): void
    {
        // TODO: Implement dispatch() method.
    }

    public function schedule($callback, int $timestamp, ...$args): void
    {
        // TODO: Implement schedule() method.
    }
}
