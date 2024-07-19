<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\Order;
use MyParcelBE\Magento\Helper\Data;

class LargeFormatOptions implements OptionSourceInterface
{
    /**
     * @var Data
     */
    static private $helper;

    /**
     * Insurance constructor.
     *
     * @param $order Order
     * @param $helper Data
     */
    public function __construct(Data $helper)
    {
        self::$helper = $helper;
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
            ['value' => 'weight', 'label' => __('Weight')],
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
            'weight' => __('Weight'),
            '0' => __('No')
        ];
    }
}
