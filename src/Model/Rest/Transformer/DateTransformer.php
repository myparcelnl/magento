<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

class DateTransformer
{
    public function transform(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            $timezone = new \DateTimeZone('Europe/Amsterdam');
            $dateTime = new \DateTimeImmutable($date, $timezone);
            $dateTime = $dateTime->setTimezone($timezone);

            return $dateTime->format('c');
        } catch (\Exception $e) {
            return null;
        }
    }
}
