<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * @api
 * @since 100.0.2
 */
class PriceDeliveryOptionsView implements OptionSourceInterface
{
    public const TOTAL     = 'total';
    public const SURCHARGE = 'surcharge';

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::TOTAL, 'label' => __('show_total_price')],
            ['value' => self::SURCHARGE, 'label' => __('show_surcharge_price')],
        ];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return [
            self::TOTAL     => __('show_total_price'),
            self::SURCHARGE => __('show_surcharge_price'),
        ];
    }
}
