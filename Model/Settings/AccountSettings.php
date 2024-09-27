<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings;

use Exception;
use MyParcelNL\Magento\Controller\Adminhtml\Settings\CarrierConfigurationImport;
use MyParcelNL\Sdk\src\Exception\AccountNotActiveException;
use MyParcelNL\Sdk\src\Exception\ApiException;
use MyParcelNL\Sdk\src\Exception\MissingFieldException;
use MyParcelNL\Sdk\src\Factory\Account\CarrierConfigurationFactory;
use MyParcelNL\Sdk\src\Model\Account\Account;
use MyParcelNL\Sdk\src\Model\Account\CarrierConfiguration;
use MyParcelNL\Sdk\src\Model\Account\CarrierOptions;
use MyParcelNL\Sdk\src\Model\Account\Shop;
use MyParcelNL\Sdk\src\Model\BaseModel;
use MyParcelNL\Sdk\src\Model\Carrier\AbstractCarrier;
use MyParcelNL\Sdk\src\Model\Consignment\DropOffPoint;
use MyParcelNL\Sdk\src\Support\Collection;

class AccountSettings extends BaseModel
{
    protected Shop $shop;
    protected Account $account;
    protected Collection $carrierOptions;
    protected Collection $carrierConfigurations;
    private static self $instance;

    /**
     * @throws ApiException
     * @throws AccountNotActiveException
     * @throws MissingFieldException
     * @throws Exception
     */
    public function __construct()
    {
        $settings = CarrierConfigurationImport::getAccountSettings();

        if (!$settings) {
            return;
        }

        $this->fillProperties($settings);
    }

    /**
     * @return null|Account
     */
    public function getAccount(): ?Account
    {
        return $this->account;
    }

    /**
     * @param AbstractCarrier $carrier
     *
     * @return null|CarrierConfiguration
     */
    public function getCarrierConfigurationByCarrier(AbstractCarrier $carrier): ?CarrierConfiguration
    {
        $carrierConfigurations = $this->getCarrierConfigurations();

        return $carrierConfigurations
            ->filter(
                static function (CarrierConfiguration $carrierConfiguration) use ($carrier) {
                    return $carrier->getId() === $carrierConfiguration->getCarrier()->getId();
                }
            )
            ->first();
    }

    /**
     * @return CarrierConfiguration[]|Collection
     */
    public function getCarrierConfigurations(): Collection
    {
        return $this->carrierConfigurations ?? new Collection();
    }

    /** c
     *
     * @return CarrierOptions[]|Collection
     */
    public function getCarrierOptions(): Collection
    {
        return $this->carrierOptions ?? new Collection();
    }

    /**
     * @param AbstractCarrier $carrier
     *
     * @return null|CarrierOptions
     */
    public function getCarrierOptionsByCarrier(AbstractCarrier $carrier): ?CarrierOptions
    {
        $carrierOptions = $this->getCarrierOptions();

        return $carrierOptions
            ->filter(
                static function (CarrierOptions $carrierOptions) use ($carrier) {
                    return $carrier->getId() === $carrierOptions->getCarrier()->getId();
                }
            )
            ->first();
    }

    /**
     * @return null|Shop
     */
    public function getShop(): ?Shop
    {
        return $this->shop;
    }

    /**
     * @throws Exception
     */
    public function getDropOffPoint(AbstractCarrier $carrier): ?DropOffPoint
    {
        $carrierConfiguration = $this->getCarrierConfigurationByCarrier($carrier);

        if (!$carrierConfiguration) {
            return null;
        }

        $dropOffPoint = $carrierConfiguration->getDefaultDropOffPoint();

        if ($dropOffPoint && null === $dropOffPoint->getNumberSuffix()) {
            $dropOffPoint->setNumberSuffix('');
        }

        return $dropOffPoint;
    }

    /**
     * @param Collection $settings
     *
     * @return void
     */
    private function fillProperties(Collection $settings): void
    {
        $shop                        = $settings->get('shop');
        $account                     = $settings->get('account');
        $carrierOptions              = $settings->get('carrier_options');
        $carrierConfigurations       = $settings->get('carrier_configurations');
        $this->shop                  = new Shop($shop);
        $account['shops']            = [$shop];
        $this->account               = new Account($account);
        $this->carrierOptions        = (new Collection($carrierOptions))->mapInto(CarrierOptions::class);
        $this->carrierConfigurations = (new Collection($carrierConfigurations))->map(function (array $data) {
            return CarrierConfigurationFactory::create($data);
        });
    }

    /**
     * Get the one instance of this class that is loaded or can be loaded.
     *
     * @return AccountSettings
     */
    public static function getInstance(): self
    {
        if (!isset(static::$instance)) {
            static::$instance = new static();
        }

        return static::$instance;
    }
}
