<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Costs;

use Magento\Framework\App\Helper\Context;
use MyParcelNL\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Magento\Service\Config\ConfigService;

class DeliveryCostsService
{
    private ConfigService $configService;

    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * @param  string $carrier
     * @param  string $key
     * @param  bool   $addBasePrice
     * @return float
     */
    public function getMethodPrice(string $carrier, string $key, bool $addBasePrice = true): float
    {
        $value = $this->configService->getFloatConfig($carrier,$key);
        $showTotalPrice = $this->configService->getConfigValue(ConfigService::XML_PATH_GENERAL . 'shipping_methods/delivery_options_prices') === PriceDeliveryOptionsView::TOTAL;

        if ($showTotalPrice && $addBasePrice) {
            $value = $this->getBasePrice() + $value;
        }

        return $value;
    }

    // todo: accept country, weight, packagetype, carrier, deliverytype etc. Or maybe a consignment then?
    public function getBasePrice(): float
    {
        return 5;
    }
    /**
     * @param  float $price
     *
     * @return int
     */
    public function getCentsByPrice(float $price): int
    {
        return (int)$price * 100;
    }
}
