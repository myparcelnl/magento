<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

/**
 * What happened to each order in a batch, named by increment id.
 *
 * A batch is no longer all-or-nothing: chunking means call four can fail after three have created
 * real, billable shipments, so the admin needs to know which orders shipped before deciding what to
 * retry. Failure messages carry the API's own text; flattening it into "export failed" would cost
 * the admin the one thing that says what to do next.
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
     * Whether every chunk that produced collateral was refused outright.
     *
     * One transport failure is enough to make the whole count uncertain, so this only stays true
     * while every rejection was a refusal.
     */
    private bool $collateralRefused = true;

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
            // The API named this order but gave no text. Dropping it here left the order in neither
            // the success nor the failure list.
            $reason = (string) __('The MyParcel API refused this order without giving a reason.');
        }

        unset($this->collateral[$incrementId]);

        if (! in_array($reason, $this->blamed[$incrementId] ?? [], true)) {
            $this->blamed[$incrementId][] = $reason;
        }
    }

    /**
     * An order that did not ship because something else in its chunk failed.
     *
     * @param bool $refused the API validated the chunk and refused it, so nothing was created.
     *                      False for a timeout or a 5xx, which changes what the admin is told.
     */
    public function failCollateral(string $incrementId, string $reason, bool $refused = true): void
    {
        if (isset($this->succeeded[$incrementId]) || isset($this->blamed[$incrementId])) {
            return;
        }

        $this->collateral[$incrementId] = true;
        $this->collateralReason         = trim($reason) ?: $this->collateralReason;
        $this->collateralRefused        = $this->collateralRefused && $refused;
    }

    /** @return array<string,int> */
    public function succeeded(): array
    {
        return $this->succeeded;
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

        if ($this->blamed) {
            $messages[] = sprintf(
                '%d other %s in this batch %s not exported. Correct the orders above and run the action again.',
                $count,
                $orders,
                1 === $count ? 'was' : 'were'
            );

            return $messages;
        }

        if ($this->collateralRefused) {
            $messages[] = sprintf(
                '%d %s not exported. MyParcel refused this batch: %s',
                $count,
                1 === $count ? 'order was' : 'orders were',
                $this->collateralReason
            );

            return $messages;
        }

        // Never "not exported" here: the call may have been processed, and re-running would then
        // create a second billable shipment for every one of these orders.
        $messages[] = sprintf(
            'MyParcel could not be reached for %d %s, so it is unknown whether they shipped.'
            . ' Check them in the MyParcel backoffice before running the action again: %s',
            $count,
            1 === $count ? 'order' : 'orders',
            $this->collateralReason
        );

        return $messages;
    }
}
