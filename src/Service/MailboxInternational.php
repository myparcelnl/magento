<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Magento\Model\Settings\InternationalMailbox;

/**
 * The carriers an account may send a mailbox parcel abroad with.
 *
 * It is neither configuration nor a capability: it is a flag per carrier on the account's general
 * settings. The one reader of that flag, so the settings form and the package type decision cannot
 * disagree, and so the resolver can be tested without a stored settings row.
 *
 * A store with no usable account answers false, which is what the decision assumed before this
 * class existed.
 */
class MailboxInternational
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** @return string[] module carrier names whose flag is on for the account of this api key */
    public function carriersFor(string $apiKey): array
    {
        return InternationalMailbox::carriersIn((new AccountSettings($apiKey))->getGeneralSettings());
    }

    public function isEnabledFor(string $carrier, ?int $storeId): bool
    {
        return in_array($carrier, $this->carriersFor((string) $this->config->getGeneralConfig('api/key', $storeId)), true);
    }
}
