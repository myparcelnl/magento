<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Weight;

use MyParcelNL\Magento\Service\Config\ConfigService;

class WeightService
{
    public const DEFAULT_WEIGHT = 1000;

    private ConfigService $configService;

    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
    }
    /**
     * Get the correct weight type
     *
     * @param  null|float $weight
     *
     * @return int
     */
    public function convertToGrams(?float $weight): int
    {
        $weightType = $this->configService->getGeneralConfig('print/weight_indication');

        if ('kilo' === $weightType) {
            return (int) ($weight * 1000);
        }

        return (int) $weight ?: self::DEFAULT_WEIGHT;
    }
}
