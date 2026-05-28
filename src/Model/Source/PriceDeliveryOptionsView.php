<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Source;

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
            ['value' => self::TOTAL, 'label' => __('Show total price')],
            ['value' => self::SURCHARGE, 'label' => __('Show surcharge')],
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
            self::TOTAL     => __('Show total price'),
            self::SURCHARGE => __('Show surcharge'),
        ];
    }
}
