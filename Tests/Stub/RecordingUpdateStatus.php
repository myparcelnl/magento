<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Tests\Stub;

use MyParcelNL\Magento\Cron\UpdateStatus;
use MyParcelNL\Sdk\Collection\Fulfilment\OrderCollection;
use RuntimeException;

/**
 * Records which API keys the PPS cron polled, and answers each with a prepared collection — or
 * throws for the keys named in $failingKeys, to stand in for one unreachable account.
 */
class RecordingUpdateStatus extends UpdateStatus
{
    /** @var string[] in call order */
    public array $queriedKeys = [];

    /** @var array<string,OrderCollection> */
    public array $responses = [];

    /** @var string[] */
    public array $failingKeys = [];

    /** @var array[] sales_order rows the selector would have returned */
    public array $orderRows = [];

    protected function ordersAwaitingBarcode(): array
    {
        return $this->orderRows;
    }

    protected function queryApiOrders(string $apiKey): OrderCollection
    {
        $this->queriedKeys[] = $apiKey;

        if (in_array($apiKey, $this->failingKeys, true)) {
            throw new RuntimeException('account unreachable');
        }

        return $this->responses[$apiKey] ?? new OrderCollection();
    }
}
