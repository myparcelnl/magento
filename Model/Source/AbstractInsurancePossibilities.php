<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

abstract class AbstractInsurancePossibilities implements OptionSourceInterface
{

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return array_map(static function ($value, $key) {
            return [
                'value' => $key,
                'label' => $value,
            ];
        }, $this->toArray(), array_keys($this->toArray()));
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return array_reduce($this->getInsurancePossibilitiesArray(), static function ($array, $insuranceValue) {
            $array[$insuranceValue] = $insuranceValue;

            return $array;
        }, []);
    }

    /**
     * @return array
     */
    abstract protected function getInsurancePossibilitiesArray(): array;
}
