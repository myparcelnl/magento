<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Model\Settings;

use MyParcelBE\Magento\Controller\Adminhtml\Settings\CarrierConfigurationImport;
use MyParcelNL\Sdk\src\Factory\Account\CarrierConfigurationFactory;
use MyParcelNL\Sdk\src\Model\Account\Account;
use MyParcelNL\Sdk\src\Model\Account\CarrierConfiguration;
use MyParcelNL\Sdk\src\Model\Account\CarrierOptions;
use MyParcelNL\Sdk\src\Model\Account\Shop;
use MyParcelNL\Sdk\src\Model\BaseModel;
use MyParcelNL\Sdk\src\Model\Carrier\AbstractCarrier;
use MyParcelNL\Sdk\src\Support\Collection;

class AccountSettings extends BaseModel
{

    /**
     * @var
     */
    protected $shop;

    /**
     * @var
     */
    protected $account;

    /**
     * @var
     */
    protected $carrier_options;

    /**
     * @var
     */
    protected $carrier_configurations;

    /**
     * @var self
     */
    private static $instance;

    /**
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     * @throws \Exception
     */
    public function __construct()
    {
        $settings = CarrierConfigurationImport::getAccountSettings();

        if (! $settings) {
            return;
        }

        $this->fillProperties($settings);
    }

    /**
     * @return null|\MyParcelNL\Sdk\src\Model\Account\Account
     */
    public function getAccount(): ?Account
    {
        return $this->account;
    }

    /**
     * @param  \MyParcelNL\Sdk\src\Model\Carrier\AbstractCarrier $carrier
     *
     * @return null|\MyParcelNL\Sdk\src\Model\Account\CarrierConfiguration
     */
    public function getCarrierConfigurationByCarrier(AbstractCarrier $carrier): ?CarrierConfiguration
    {
        $carrierConfigurations = $this->getCarrierConfigurations();

        return $carrierConfigurations
            ->filter(
                static function (CarrierConfiguration $carrierConfiguration) use ($carrier) {
                    return $carrier->getId() === $carrierConfiguration->getCarrier()
                            ->getId();
                }
            )
            ->first();
    }

    /**
     * @return \MyParcelNL\Sdk\src\Model\Account\CarrierConfiguration[]|\MyParcelNL\Sdk\src\Support\Collection
     */
    public function getCarrierConfigurations(): Collection
    {
        return $this->carrier_configurations ?? new Collection();
    }

    /** c
     *
     * @return \MyParcelNL\Sdk\src\Model\Account\CarrierOptions[]|\MyParcelNL\Sdk\src\Support\Collection
     */
    public function getCarrierOptions(): Collection
    {
        return $this->carrier_options ?? new Collection();
    }

    /**
     * @param  \MyParcelNL\Sdk\src\Model\Carrier\AbstractCarrier $carrier
     *
     * @return null|\MyParcelNL\Sdk\src\Model\Account\CarrierOptions
     */
    public function getCarrierOptionsByCarrier(AbstractCarrier $carrier): ?CarrierOptions
    {
        $carrierOptions = $this->getCarrierOptions();

        return $carrierOptions
            ->filter(
                static function (CarrierOptions $carrierOptions) use ($carrier) {
                    return $carrier->getId() === $carrierOptions->getCarrier()
                            ->getId();
                }
            )
            ->first();
    }

    /**
     * @return null|\MyParcelNL\Sdk\src\Model\Account\Shop
     */
    public function getShop(): ?Shop
    {
        return $this->shop;
    }

    /**
     * @param  \MyParcelNL\Sdk\src\Support\Collection $settings
     *
     * @return void
     */
    private function fillProperties(Collection $settings): void
    {
        $shop                         = $settings->get('shop');
        $account                      = $settings->get('account');
        $carrierOptions               = $settings->get('carrier_options');
        $carrierConfigurations        = $settings->get('carrier_configurations');
        $this->shop                   = new Shop($shop);
        $account['shops']             = [$shop];
        $this->account                = new Account($account);
        $this->carrier_options        = (new Collection($carrierOptions))->mapInto(CarrierOptions::class);
        $this->carrier_configurations = (new Collection($carrierConfigurations))->map(function (array $data) {
            return CarrierConfigurationFactory::create($data);
        });
    }

    /**
     * Get the one instance of this class that is loaded or can be loaded.
     *
     * @return \MyParcelBE\Magento\Model\Settings\AccountSettings
     */
    public static function getInstance(): self
    {
        if (null === static::$instance) {
            static::$instance = new static();
        }

        return static::$instance;
    }
}
