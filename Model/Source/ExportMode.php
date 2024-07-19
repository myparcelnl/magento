<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MyParcelBE\Magento\Model\Sales\TrackTraceHolder;

class ExportMode implements OptionSourceInterface
{
    /**
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
