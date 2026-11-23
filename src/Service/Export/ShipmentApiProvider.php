<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Api\ShipmentApi;
use MyParcelNL\Sdk\Services\CoreApi\ShipmentApiFactory;

/**
 * Turns an API key into a ShipmentApi client: the module's only call site for ShipmentApiFactory::make().
 *
 * The SDK does not fail on an empty key: it falls back to getenv('API_KEY'), API_KEY_NL and
 * API_KEY_BE, and ships to that account. The guard must run before the factory every time, so do
 * not add a second call site. Clients are memoised per key.
 */
class ShipmentApiProvider
{
    private Config    $config;
    private UserAgent $userAgent;

    /** @var array<string,ShipmentApi> keyed by API key */
    private array $clients = [];

    public function __construct(Config $config, UserAgent $userAgent)
    {
        $this->config    = $config;
        $this->userAgent = $userAgent;
    }

    /**
     * @throws LocalizedException when the store has no API key, naming nothing it could fall back to
     */
    public function apiKeyForStore(?int $storeId): string
    {
        $apiKey = $this->apiKeyForStoreOrNull($storeId);

        if (null === $apiKey) {
            throw $this->noApiKey();
        }

        return $apiKey;
    }

    /** Get the store's resolved key, or null for a caller that skips a keyless store. */
    public function apiKeyForStoreOrNull(?int $storeId): ?string
    {
        $apiKey = (string) $this->config->getConfigValue(Config::XML_PATH_API_KEY, $storeId);

        return '' === $apiKey ? null : $apiKey;
    }

    /**
     * @throws LocalizedException on an empty key, before the SDK factory can read the environment
     */
    public function clientFor(string $apiKey): ShipmentApi
    {
        if ('' === $apiKey) {
            throw $this->noApiKey();
        }

        // The third argument is the transport User-Agent the generated client sends; without it
        // every Core API call goes out under the OpenAPI generator's default.
        return $this->clients[$apiKey]
            ?? ($this->clients[$apiKey] = ShipmentApiFactory::make($apiKey, null, $this->userAgent->header()));
    }

    /** One wording for the two places a missing key surfaces. */
    private function noApiKey(): LocalizedException
    {
        return new LocalizedException(
            __('API key is not known. Go to the settings in the backoffice to create an API key. Fill the API key in the settings.')
        );
    }
}
