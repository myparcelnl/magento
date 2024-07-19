<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class WeightType implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [['value' => 'gram', 'label' => __('Gram')], ['value' => 'kilo', 'label' => __('Kilo')]];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return ['gram' => __('Gram'), 'kilo' => __('Kilo')];
    }
}
