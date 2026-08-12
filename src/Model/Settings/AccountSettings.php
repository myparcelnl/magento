<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings;

use Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Serialize\Serializer\Json;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Sdk\Model\Account\Account;
use MyParcelNL\Sdk\Model\Account\CarrierOptions;
use MyParcelNL\Sdk\Model\Account\Shop;
use MyParcelNL\Sdk\Model\BaseModel;
use MyParcelNL\Sdk\Model\Carrier\AbstractCarrier;
use MyParcelNL\Sdk\Support\Collection;

class AccountSettings extends BaseModel
{
    protected Shop       $shop;
    protected Account    $account;
    protected Collection $carrierOptions;

    /**
     * @var string $apiKey the api key (shop identifier) to get the account settings for
     */
    public function __construct(string $apiKey)
    {
        $objectManager  = ObjectManager::getInstance();
        $scopeConfig    = $objectManager->get(ScopeConfigInterface::class);
        $fingerprint    = $objectManager->get(Fingerprint::class);
        $jsonSerializer = $objectManager->get(Json::class);

        $settings = $scopeConfig->getValue(Config::XML_PATH_ACCOUNT_SETTINGS . $fingerprint->of($apiKey));

        if (! $settings) {
            // Rows predating the fingerprinted path carry the plaintext api key. reconcile() rewrites
            // them, but only on an api key change or import, so an install that has done neither since
            // upgrading would read empty here.
            $settings = $scopeConfig->getValue(Config::XML_PATH_ACCOUNT_SETTINGS . $apiKey);
        }

        if (! $settings) {
            $redacted = substr($apiKey, 0, 4) . str_repeat('*', max(0, strlen($apiKey) - 8)) . substr($apiKey, -4);
            Logger::alert((sprintf('No account settings found for api key: %s. Shops -> Configurations -> MyParcel -> General -> Import MyParcel Backoffice settings.', $redacted)));
            return;
        }

        $this->fillProperties(new Collection($jsonSerializer->unserialize($settings)));
    }

    /**
     * @return null|Account
     */
    public function getAccount(): ?Account
    {
        return $this->account ?? null;
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
            ->first()
        ;
    }

    /**
     * @return null|Shop
     */
    public function getShop(): ?Shop
    {
        return $this->shop;
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
        $this->shop                  = new Shop($shop);
        $account['shops']            = [$shop];
        $this->account               = new Account($account);
        $this->carrierOptions        = (new Collection($carrierOptions))->mapInto(CarrierOptions::class);
    }
}
