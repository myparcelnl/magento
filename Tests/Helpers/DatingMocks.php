<?php

declare(strict_types=1);

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MyParcelNL\Magento\Service\Dating;

/**
 * Dating over a store whose calendar day is $storeToday, so no test depends on the real clock.
 * scopeDate() returns a new DateTime per call, because Dating::tomorrow() modifies the one it gets.
 */
function datingOn(string $storeToday, ?int $storeId = null): Dating
{
    $timezone = Mockery::mock(TimezoneInterface::class);
    $expect   = $timezone->shouldReceive('scopeDate');

    if (null !== $storeId) {
        $expect->with($storeId);
    }

    $expect->andReturnUsing(static fn(): DateTime => new DateTime($storeToday));

    return new Dating($timezone);
}
