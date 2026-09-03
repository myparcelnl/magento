<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Serialize\Serializer\Json;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Sdk\Model\Account\Account;
use MyParcelNL\Sdk\Model\BaseModel;
use MyParcelNL\Sdk\Support\Collection;

/**
 * The account half of a stored account settings row: whose account it is and what its general
 * settings say.
 *
 * The carrier half moved to contract definitions, read through
 * Service\AccountSettings\ContractDefinitions. What is left is the account's own general settings,
 * which no contract carries — hasPostnlMailboxInternational() is the one live reader.
 */
class AccountSettings extends BaseModel
{
    protected Account $account;

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

    /**
     * @param Collection $settings
     *
     * @return void
     */
    private function fillProperties(Collection $settings): void
    {
        $shop    = $settings->get('shop');
        $account = $settings->get('account');

        // Account's constructor needs its shops, and toArray() serialised them as empty objects, so
        // the shop is re-grafted from its own key. Do not remove: the row carries no usable shops.
        $account['shops'] = [$shop];
        $this->account    = new Account($account);
    }
}
