<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

/**
 * The digital stamp weight ranges, and the weight to send for each.
 *
 * `value` is not the upper bound: the 50-350 range sends 200. Read a range with `max`, send `value`.
 * Labels are translated here because i18n:collect-phrases only finds a literal argument.
 */
final class DigitalStampWeight
{
    /** The "let MyParcel decide" choice. Never selected automatically. */
    public const NO_STANDARD_WEIGHT = 0;

    /**
     * @return array<int,array{max:int|null,value:int,label:\Magento\Framework\Phrase}>
     */
    public static function options(): array
    {
        return [
            ['max' => null, 'value' => self::NO_STANDARD_WEIGHT, 'label' => __('No standard weight')],
            ['max' => 20,   'value' => 20,   'label' => __('0 - 20 gram')],
            ['max' => 50,   'value' => 50,   'label' => __('20 - 50 gram')],
            ['max' => 350,  'value' => 200,  'label' => __('50 - 350 gram')],
            ['max' => 2000, 'value' => 2000, 'label' => __('350 - 2000 gram')],
        ];
    }

    /** Get the weight to send for this many grams, or null when it is heavier than any range. */
    public static function valueFor(int $grams): ?int
    {
        foreach (self::options() as $option) {
            if (null !== $option['max'] && $grams <= $option['max']) {
                return $option['value'];
            }
        }

        return null;
    }
}
