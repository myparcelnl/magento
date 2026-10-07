<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Sdk\Services\Auth\ApiKeyService;

/**
 * The IAM features of the account behind an api key, from the whoami endpoint.
 *
 * One API call per use: the importer stores the answer in the account settings row, and everything
 * else reads it from there. Throws whatever the SDK throws.
 */
class AccountFeatures
{
    private UserAgent $userAgent;

    public function __construct(UserAgent $userAgent)
    {
        $this->userAgent = $userAgent;
    }

    /** @return string[] Feature values, such as Feature::LEGACY_ORDER_MANAGEMENT */
    public function forApiKey(string $apiKey): array
    {
        $features = (new ApiKeyService($apiKey))
            ->setUserAgents($this->userAgent->map())
            ->getPrincipal()
            ->getFeatures();

        return array_values(array_map('strval', (array) $features));
    }
}
