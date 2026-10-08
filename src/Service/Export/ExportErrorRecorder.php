<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\OrderGridColumns;

/**
 * Keeps why the last export of an order failed on the order and its grid row, so the merchant can
 * still see it after the flash message is gone.
 *
 * Written on the order object too: the New Shipment flow saves that object after the export, and
 * a stale value on it would overwrite the row.
 */
class ExportErrorRecorder
{
    /** An API refusal can name many fields; the grid row carries the column on every page load. */
    private const MAX_LENGTH = 1000;

    private OrderGridColumns $gridColumns;

    public function __construct(OrderGridColumns $gridColumns)
    {
        $this->gridColumns = $gridColumns;
    }

    /**
     * A blamed order gets its reasons, a shipped one loses its old error, and an order that only
     * shared a refused chunk keeps what it had.
     *
     * @param BuiltShipment[] $builtShipments
     */
    public function record(ExportReport $report, array $builtShipments): void
    {
        $reasons   = $report->failureReasons();
        $succeeded = $report->succeeded();

        foreach ($this->ordersByIncrementId($builtShipments) as $incrementId => $order) {
            if (isset($reasons[$incrementId])) {
                $this->write($order, implode('; ', $reasons[$incrementId]));
            } elseif (isset($succeeded[$incrementId])) {
                $this->write($order, null);
            }
        }
    }

    public function write(Order $order, ?string $error): void
    {
        $error = null === $error ? null : mb_substr($error, 0, self::MAX_LENGTH);

        // Most exports succeed for orders that never failed: nothing to clear.
        if ($order->getData(Config::FIELD_EXPORT_ERROR) === $error) {
            return;
        }

        $order->setData(Config::FIELD_EXPORT_ERROR, $error);

        if ($order->getId()) {
            $this->gridColumns->update((int) $order->getId(), [Config::FIELD_EXPORT_ERROR => $error]);
        }
    }

    /**
     * @param BuiltShipment[] $builtShipments
     *
     * @return array<string,Order>
     */
    private function ordersByIncrementId(array $builtShipments): array
    {
        $orders = [];

        foreach ($builtShipments as $built) {
            $shipment = $built->track()->getShipment();
            $order    = $shipment ? $shipment->getOrder() : null;

            if ($order instanceof Order) {
                $orders[$built->incrementId()] = $order;
            }
        }

        return $orders;
    }
}
