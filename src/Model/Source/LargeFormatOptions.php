<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MyParcelNL\Magento\Service\Config;

class LargeFormatOptions implements OptionSourceInterface
{
    static private Config $configService;

    /**
     * @param $configService Config
     */
    public function __construct(Config $configService)
    {
        self::$configService = $configService;
    }

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'price', 'label' => __('Price')],
            ['value' => '0', 'label' => __('No')]
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
            'price' => __('Price'),
            '0' => __('No')
        ];
    }
}
