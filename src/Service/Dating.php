<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use DateTimeImmutable;
use Throwable;

class Dating
{
    /**
     * The delivery date to send, in $format, or null for none. An empty or unreadable date is none;
     * a date that has passed becomes tomorrow.
     */
    public static function convertDeliveryDate(?string $date, string $format = 'Y-m-d H:i:s'): ?string
    {
        if (null === $date || '' === trim($date)) {
            return null;
        }

        try {
            $deliveryDate = strtotime((new DateTimeImmutable($date))->format('Y-m-d'));
        } catch (Throwable $e) {
            return null;
        }

        $currentDate = strtotime(date('Y-m-d'));

        if ($deliveryDate <= $currentDate) {
            return date($format, strtotime('now +1 day'));
        }

        return date($format, $deliveryDate);
    }
}
