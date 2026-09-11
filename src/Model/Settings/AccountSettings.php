<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Sdk\Model\Account\Account;
use MyParcelNL\Sdk\Model\BaseModel;

/**
 * The account half of a stored account settings row: whose account it is and what its general
 * settings say.
 *
 * The carrier half moved to contract definitions, read through
 * Service\AccountSettings\ContractDefinitions. What is left is the account's own general settings,
 * which no contract carries — hasPostnlMailboxInternational() is the one live reader.
 *
 * A row this class did not write may be missing anything, and the SDK's Account throws on a missing
 * key. getAccount() therefore answers null for every row it cannot use: it is read while the order
 * grid renders, where a throw is a 500.
 */
class AccountSettings extends BaseModel
{
    protected Account $account;

    /**
     * @var string $apiKey the api key (shop identifier) to get the account settings for
     */
    public function __construct(string $apiKey)
    {
        $objectManager = ObjectManager::getInstance();
        $scopeConfig   = $objectManager->get(ScopeConfigInterface::class);
        $fingerprint   = $objectManager->get(Fingerprint::class);

        $settings = $scopeConfig->getValue(Config::XML_PATH_ACCOUNT_SETTINGS . $fingerprint->of($apiKey));

        if (! is_string($settings) || '' === $settings) {
            $this->alert('No account settings found', $apiKey);
            return;
        }

        // json_decode, not the Json serializer: that one throws on a malformed row.
        $decoded = json_decode($settings, true);

        if (! is_array($decoded)) {
            $this->alert('Account settings could not be read', $apiKey);
            return;
        }

        $account = $this->accountFrom($decoded);

        if (null === $account) {
            $this->alert('Account settings are incomplete', $apiKey);
            return;
        }

        $this->account = $account;
    }

    /**
     * @return null|Account
     */
    public function getAccount(): ?Account
    {
        return $this->account ?? null;
    }

    /**
     * Null for a row that cannot produce an Account. Every key the SDK reads into a typed property
     * is checked here, because a missing one is a TypeError rather than a null.
     *
     * @param array<string, mixed> $settings
     */
    private function accountFrom(array $settings): ?Account
    {
        $account = $settings['account'] ?? null;

        if (! is_array($account)) {
            return null;
        }

        $propositionId = $account['proposition_id'] ?? $account['platform_id'] ?? null;

        if (! is_numeric($account['id'] ?? null) || ! is_numeric($propositionId)) {
            return null;
        }

        $shop = $settings['shop'] ?? null;

        return new Account([
            'id'               => (int) $account['id'],
            'proposition_id'   => (int) $propositionId,
            // Account's constructor needs its shops, and toArray() serialised them as empty objects,
            // so the shop is re-grafted from its own key. An unusable one is dropped rather than
            // failing the account: nothing reading this class reads a shop.
            'shops'            => is_array($shop) && isset($shop['id'], $shop['name']) ? [$shop] : [],
            'general_settings' => is_array($account['general_settings'] ?? null)
                ? $account['general_settings']
                : [],
        ]);
    }

    private function alert(string $what, string $apiKey): void
    {
        $redacted = substr($apiKey, 0, 4) . str_repeat('*', max(0, strlen($apiKey) - 8)) . substr($apiKey, -4);

        Logger::alert(sprintf(
            '%s for api key: %s. Shops -> Configurations -> MyParcel -> General -> Import MyParcel Backoffice settings.',
            $what,
            $redacted
        ));
    }
}
