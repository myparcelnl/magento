<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use DateTimeImmutable;
use Exception;

class Dating
{
    /**
     * Get date in YYYY-MM-DD HH:MM:SS format
     *
     * @param string|null $date
     * @param string $format
     * @return string|null
     */
    public static function convertDeliveryDate(?string $date, string $format = 'Y-m-d H:i:s'): ?string
    {
        if (null === $date) {
            return null;
        }

        try {
            $deliveryDate = strtotime((new DateTimeImmutable($date))->format('Y-m-d'));
        } catch (Exception $e) {
            return null;
        }

        $currentDate = strtotime(date('Y-m-d'));

        if ($deliveryDate <= $currentDate) {
            return date($format, strtotime('now +1 day'));
        }

        return date($format, $deliveryDate);
    }
}
