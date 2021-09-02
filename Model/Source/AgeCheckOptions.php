<?php

namespace MyParcelNL\Magento\Model\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

class AgeCheckOptions extends AbstractSource
{
    /**
     * @return array
     */
    public function getOptionArray()
    {
        return [
            ['value' => null, 'label'=>__('Default')],
            ['value' => '0', 'label'=>__('No')],
            ['value' => '1', 'label'=>__('Yes')],
        ];
    }

    /**
     * Retrieve All options
     *
     * @return array
     */
    public function getAllOptions()
    {
        return $this->getOptionArray();
    }
}
