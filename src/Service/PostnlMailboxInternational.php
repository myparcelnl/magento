<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use MyParcelNL\Magento\Model\Settings\AccountSettings;

/**
 * Whether the account behind a store may send a PostNL mailbox parcel abroad: a flag on the
 * account's general settings, not a config value or a capability. A store without a usable account
 * answers false.
 */
class PostnlMailboxInternational
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function isEnabled(?int $storeId): bool
    {
        $account = (new AccountSettings((string) $this->config->getGeneralConfig('api/key', $storeId)))->getAccount();

        return null !== $account && $account->getGeneralSettings()->hasPostnlMailboxInternational();
    }
}
