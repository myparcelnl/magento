<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ReturnInTheBox implements OptionSourceInterface
{
    const NOT_ACTIVE        = 'notActive';
    const NO_OPTIONS        = 'noOptions';
    const EQUAL_TO_SHIPMENT = 'equalToShipment';

    const OPTIONS = [
        self::NOT_ACTIVE        => 'No',
        self::NO_OPTIONS        => 'Without options',
        self::EQUAL_TO_SHIPMENT => 'Options equal to shipment'
    ];

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $optionArray = [];

        foreach (self::OPTIONS as $key => $value) {
            $optionArray[] = ['value' => $key, 'label' => __($value)];
        }

        return $optionArray;
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray(): array
    {
        $optionArray = [];

        foreach (self::OPTIONS as $key => $value) {
            $optionArray[] = [$key => __($value)];
        }

        return $optionArray;
    }
}
