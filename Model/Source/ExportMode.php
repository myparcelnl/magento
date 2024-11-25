<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MyParcelNL\Magento\Model\Sales\TrackTraceHolder;
use MyParcelNL\Magento\Service\Config;

class ExportMode implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::EXPORT_MODE_SHIPMENTS,
                'label' => __('Export shipping details only')
            ],
            [
                'value' => Config::EXPORT_MODE_PPS,
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
            'shipments' => __(Config::EXPORT_MODE_SHIPMENTS),
            'pps'       => __(Config::EXPORT_MODE_PPS)
        ];
    }
}
