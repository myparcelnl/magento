<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Model\Source;

use MyParcelNL\Sdk\src\Model\Consignment\PostNLConsignment;

class PostNLInsurancePossibilities extends AbstractInsurancePossibilities
{
    protected function getInsurancePossibilitiesArray(): array
    {
        return PostNLConsignment::INSURANCE_POSSIBILITIES_LOCAL;
    }
}
