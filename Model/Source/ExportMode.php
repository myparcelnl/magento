<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MyParcelNL\Magento\Model\Sales\TrackTraceHolder;

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
                'value' => TrackTraceHolder::EXPORT_MODE_SHIPMENTS,
                'label' => __('Export shipping details only')
            ],
            [
                'value' => TrackTraceHolder::EXPORT_MODE_PPS,
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
            'shipments' => __(TrackTraceHolder::EXPORT_MODE_SHIPMENTS),
            'pps'       => __(TrackTraceHolder::EXPORT_MODE_PPS)
        ];
    }
}
