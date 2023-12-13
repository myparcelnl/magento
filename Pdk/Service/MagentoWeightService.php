<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Pdk\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Pdk\Base\Service\WeightService;

class MagentoWeightService extends WeightService
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param  \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param  int|float   $weight
     * @param  null|string $unit
     *
     * @return int
     */
    public function convertToGrams($weight, ?string $unit = null): int
    {
        return parent::convertToGrams(
            $weight,
            $unit
                ?: $this->scopeConfig->getValue(
                'general/locale/weight_unit',
                ScopeInterface::SCOPE_STORE
            )
        );
    }
}
