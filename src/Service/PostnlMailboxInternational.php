<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use MyParcelNL\Magento\Model\Settings\AccountSettings;

/**
 * Whether the account behind a store may send a mailbox parcel outside its own country.
 *
 * It is neither configuration nor a capability: it is a flag on the account's general settings, and
 * the package type decision is the only thing that reads it. Kept behind its own service so the
 * resolver can be tested without a stored settings row.
 *
 * A store with no usable account answers false, which is what the decision assumed before this
 * class existed.
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
