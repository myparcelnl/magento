<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

/**
 * What happened to each order in a batch, named by increment id (US-000009).
 *
 * A batch is no longer all-or-nothing: chunking means call four can fail after three have created
 * real, billable shipments, so the admin needs to know which orders shipped before deciding what to
 * retry. Failure messages carry the API's own text — flattening it into "export failed" is what
 * FR-000010's accepted trade-off forbids.
 *
 * Failures come in two kinds, and the difference is the whole point of this class. A **blamed**
 * order is one the API objected to; it gets its own message. **Collateral** is an order that simply
 * shared a rejected chunk — it is counted, never named, because a per-order line for each one reads
 * as an accusation and buries the one message that matters.
 */
class ExportReport
{
    /** @var array<string,int> increment id => MyParcel shipment id */
    private array $succeeded = [];

    /** @var array<string,string[]> increment id => every reason it was blamed, in the order they arrived */
    private array $blamed = [];

    /** @var array<string,true> increment ids that did not ship but were never blamed */
    private array $collateral = [];

    /** Why the collateral did not ship. The same for all of them, so it is kept once. */
    private string $collateralReason = '';

    /**
     * A success clears collateral but never an earlier blame: an order exports one shipment per
     * collo under the same increment id, so one collo succeeding and another failing are both true
     * at once — dropping either half would tell the admin a partial export was clean.
     */
    public function succeed(string $incrementId, int $shipmentId): void
    {
        $this->succeeded[$incrementId] = $shipmentId;
        unset($this->collateral[$incrementId]);
    }

    /**
     * Reasons accumulate rather than replace: one rejected shipment can break several rules at once,
     * and the merchant has to fix all of them before the batch will go through. Overwriting meant a
     * second fix-and-retry for something the API had already said the first time.
     */
    public function fail(string $incrementId, string $reason): void
    {
        $reason = trim($reason);

        if ('' === $reason) {
            return;
        }

        unset($this->collateral[$incrementId]);

        if (! in_array($reason, $this->blamed[$incrementId] ?? [], true)) {
            $this->blamed[$incrementId][] = $reason;
        }
    }

    /** An order that did not ship because something else in its chunk was refused. */
    public function failCollateral(string $incrementId, string $reason): void
    {
        if (isset($this->succeeded[$incrementId]) || isset($this->blamed[$incrementId])) {
            return;
        }

        $this->collateral[$incrementId] = true;
        $this->collateralReason         = trim($reason) ?: $this->collateralReason;
    }

    /** @return array<string,int> */
    public function succeeded(): array
    {
        return $this->succeeded;
    }

    /** @return array<string,string> increment id => its reasons, joined. Blamed orders only. */
    public function failed(): array
    {
        return array_map(
            static fn(array $reasons): string => implode('; ', $reasons),
            $this->blamed
        );
    }

    /** @return array<string,string[]> increment id => its reasons, unjoined */
    public function failureReasons(): array
    {
        return $this->blamed;
    }

    /** @return string[] increment ids that did not ship and were not blamed */
    public function collateral(): array
    {
        return array_keys($this->collateral);
    }

    public function hasFailures(): bool
    {
        return [] !== $this->blamed || [] !== $this->collateral;
    }

    /**
     * One line per blamed order, then at most one line for everything else.
     *
     * The collateral line carries no increment ids on purpose: naming twenty orders that are not at
     * fault is the noise this replaces.
     *
     * @return string[] ready for the admin message area
     */
    public function failureMessages(): array
    {
        $messages = [];

        foreach ($this->blamed as $incrementId => $reasons) {
            $messages[] = sprintf('%s: %s', $incrementId, implode('; ', $reasons));
        }

        if ([] === $this->collateral) {
            return $messages;
        }

        $count  = count($this->collateral);
        $orders = 1 === $count ? 'order' : 'orders';

        $messages[] = $this->blamed
            ? sprintf(
                '%d other %s in this batch %s not exported. Correct the orders above and run the action again.',
                $count,
                $orders,
                1 === $count ? 'was' : 'were'
            )
            : sprintf(
                '%d %s not exported. MyParcel refused this batch: %s',
                $count,
                1 === $count ? 'order was' : 'orders were',
                $this->collateralReason
            );

        return $messages;
    }
}
