<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use DateTimeImmutable;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Throwable;

/**
 * Delivery dates against a store's own calendar day.
 *
 * Magento runs PHP in UTC, so date('Y-m-d') is yesterday in the Netherlands until 02:00. Every
 * "today" or "tomorrow" comes from here, in the timezone of the order's store.
 */
class Dating
{
    private TimezoneInterface $timezone;

    public function __construct(TimezoneInterface $timezone)
    {
        $this->timezone = $timezone;
    }

    /** Tomorrow in the timezone of the order's store, at the start of the day. */
    public function tomorrow(Order $order, string $format = 'Y-m-d'): string
    {
        return $this->timezone->scopeDate((int) $order->getStoreId())->modify('+1 day')->format($format);
    }

    /**
     * The delivery date to send, in $format, or null for none. An empty, unreadable or past date is
     * none, as in the PDK: the API then refuses a past morning or evening delivery.
     */
    public function convertDeliveryDate(?string $date, Order $order, string $format = 'Y-m-d H:i:s'): ?string
    {
        if (null === $date || '' === trim($date)) {
            return null;
        }

        try {
            $day = (new DateTimeImmutable($date))->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }

        if ($day < $this->tomorrow($order)) {
            return null;
        }

        return (new DateTimeImmutable($day))->format($format);
    }
}
