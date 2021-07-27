<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ExportMode implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'shipments',
                'label' => __('Export shipping details only')
            ],
            [
                'value' => 'pps',
                'label' => __('Export entire order')
            ]
        ];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'shipments' => __('shipments'),
            'pps'       => __('pps')
        ];
    }
}
