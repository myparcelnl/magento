<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Source;

use Exception;

class CarrierInsurancePossibilities extends AbstractInsurancePossibilities
{
    /**
     * @throws Exception
     */
    protected function getInsurancePossibilitiesArray(?string $cc = null): array
    {
        return $this->carrier->getInsurancePossibilities($cc);
    }
}
