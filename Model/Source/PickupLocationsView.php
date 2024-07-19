<?php

namespace MyParcelBE\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * @api
 * @since 100.0.2
 */
class PickupLocationsView implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'list', 'label' => __('Show list first')],
            ['value' => 'map', 'label' => __('Show map first')]
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
            'list' => __('Show list first'),
            'map'  => __('Show map first')
        ];
    }
}
