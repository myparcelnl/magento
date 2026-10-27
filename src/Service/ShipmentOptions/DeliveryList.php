<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ShipmentOptions;

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;

/**
 * The delivery list of the options modal: a "No date" group, one group per offered date and a
 * "Pickup" group, with one entry selected.
 *
 * An entry's value is "<delivery type>|<Y-m-d or empty>", the same shape the modal posts back.
 * Dates stay Y-m-d: the browser formats them in the merchant's locale.
 */
final class DeliveryList
{
    /** Delivery types the merchant can choose; same-day and early morning are only kept when stored. */
    private const CHOOSABLE = [
        DeliveryType::MORNING_NAME,
        DeliveryType::STANDARD_NAME,
        DeliveryType::EVENING_NAME,
        DeliveryType::EXPRESS_NAME,
    ];

    /** Delivery types the "No date" group can hold: the API refuses morning and evening without one. */
    private const UNDATED = [DeliveryType::STANDARD_NAME, DeliveryType::EXPRESS_NAME];

    private const LABELS = [
        DeliveryType::STANDARD_NAME      => 'Standard',
        DeliveryType::MORNING_NAME       => 'Morning',
        DeliveryType::EVENING_NAME       => 'Evening',
        DeliveryType::EXPRESS_NAME       => 'Express',
        DeliveryType::SAME_DAY_NAME      => 'Same day',
        DeliveryType::EARLY_MORNING_NAME => 'Early morning',
        DeliveryType::PICKUP_NAME        => 'Pickup',
    ];

    /**
     * @param array<int,array{date:string,deliveryType:string,start:?string,end:?string}> $dated
     *        what DeliveryDates::forOrder() returned; empty when $datesFailed
     * @param string[] $allowedDeliveryTypes the module delivery type names capabilities allow
     *
     * @return array{groups: array<int,array{date:?string,label:string,entries:array<int,array{value:string,deliveryType:string,date:?string,label:string,selected:bool,stored:bool}>}>, pickup: bool, warning: ?string}
     */
    public static function build(
        array           $dated,
        array           $allowedDeliveryTypes,
        DeliveryOptions $stored,
        string          $carrier,
        bool            $datesFailed
    ): array {
        $groups = [null => self::undatedEntries($allowedDeliveryTypes)];

        foreach ($dated as $possibility) {
            $type = $possibility['deliveryType'];

            if (in_array($type, self::CHOOSABLE, true) && in_array($type, $allowedDeliveryTypes, true)) {
                $groups[$possibility['date']][] = self::entry($type, $possibility['date'], $possibility['start'], $possibility['end']);
            }
        }

        $storedValue = self::storedValue($stored);
        $pickup      = in_array(DeliveryType::PICKUP_NAME, $allowedDeliveryTypes, true);
        $sameCarrier = $stored->getCarrier() === $carrier;

        if (null !== $storedValue && ! self::contains($groups, $storedValue) && ($datesFailed || ($sameCarrier && self::keptWhenMissing($stored)))) {
            [$type, $date] = explode('|', $storedValue);

            if (DeliveryType::PICKUP_NAME === $type) {
                $pickup = true;
            } else {
                $groups[$date ?: null][] = self::entry($type, $date ?: null, null, null, true);
            }
        }

        if ($pickup) {
            $groups[DeliveryType::PICKUP_NAME] = [self::entry(DeliveryType::PICKUP_NAME, null, null, null)];
        }

        $selected = null !== $storedValue && self::contains($groups, $storedValue)
            ? $storedValue
            : DeliveryType::STANDARD_NAME . '|';

        return [
            'groups'  => self::grouped($groups, $selected),
            'pickup'  => $pickup,
            'warning' => $datesFailed ? (string) __('MyParcel could not load the delivery dates. The stored delivery is kept.') : null,
        ];
    }

    /** Standard always, so the merchant can fall back to it; express when capabilities allow it. */
    private static function undatedEntries(array $allowedDeliveryTypes): array
    {
        $entries = [];

        foreach (self::UNDATED as $type) {
            if (DeliveryType::STANDARD_NAME === $type || in_array($type, $allowedDeliveryTypes, true)) {
                $entries[] = self::entry($type, null, null, null);
            }
        }

        return $entries;
    }

    /** Pickup, same-day, early morning and unknown types stay when missing; a missing date does not. */
    private static function keptWhenMissing(DeliveryOptions $stored): bool
    {
        return ! in_array($stored->getDeliveryType(), self::CHOOSABLE, true);
    }

    private static function storedValue(DeliveryOptions $stored): ?string
    {
        return self::valueOf($stored->getDeliveryType(), $stored->getDate());
    }

    /** A stored delivery type and date as a list value, or null without a delivery type. */
    public static function valueOf(?string $type, ?string $date): ?string
    {
        if (null === $type || '' === $type) {
            return null;
        }

        if (DeliveryType::PICKUP_NAME === $type) {
            return $type . '|';
        }

        return $type . '|' . (null === $date || '' === $date ? '' : substr($date, 0, 10));
    }

    /** The translated label of a delivery type; an unknown type is its own label. */
    public static function labelFor(string $type): string
    {
        return isset(self::LABELS[$type]) ? (string) __(self::LABELS[$type]) : $type;
    }

    private static function entry(string $type, ?string $date, ?string $start, ?string $end, bool $stored = false): array
    {
        $label = self::labelFor($type);

        if (null !== $start && null !== $end) {
            $label .= ' ' . $start . '–' . $end;
        }

        return [
            'value'        => $type . '|' . (string) $date,
            'deliveryType' => $type,
            'date'         => $date,
            'label'        => $label,
            'selected'     => false,
            'stored'       => $stored,
        ];
    }

    private static function contains(array $groups, string $value): bool
    {
        foreach ($groups as $entries) {
            if (in_array($value, array_column($entries, 'value'), true)) {
                return true;
            }
        }

        return false;
    }

    /** "No date" first, then the dates in order, then "Pickup", with the one selected entry marked. */
    private static function grouped(array $groups, string $selected): array
    {
        $undated = $groups[''] ?? [];
        $pickup  = $groups[DeliveryType::PICKUP_NAME] ?? null;
        unset($groups[''], $groups[DeliveryType::PICKUP_NAME]);
        ksort($groups);

        $result = [['date' => null, 'label' => (string) __('No date'), 'entries' => $undated]];

        foreach ($groups as $date => $entries) {
            $result[] = ['date' => (string) $date, 'label' => (string) $date, 'entries' => $entries];
        }

        if (null !== $pickup) {
            $result[] = ['date' => null, 'label' => (string) __('Pickup'), 'entries' => $pickup];
        }

        foreach ($result as &$group) {
            foreach ($group['entries'] as &$entry) {
                $entry['selected'] = $entry['value'] === $selected;
            }
        }

        return $result;
    }
}
