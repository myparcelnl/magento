<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MyParcelNL\Sdk\src\Model\Consignment\PostNLConsignment;

class InsurancePossibilities implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $array = [];

        foreach ($this->toArray() as $key => $day) {
            $array[] = [
                'value' => $key,
                'label' => $day,
            ];
        }

        return $array;
    }

    /**
     * Options getter
     *
     * @return array
     */
    public function toArray(): array
    {
        $insurancePossibilities = PostNLConsignment::INSURANCE_POSSIBILITIES_LOCAL;
        $array                  = [];

        foreach ($insurancePossibilities as $i => $iValue) {
            if ($iValue <= 500) {
                unset($insurancePossibilities[$i]);
            }
        }

        foreach ($insurancePossibilities as $i) {
            $array[$i] = $i;
        }

        return $array;
    }
}
