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

    /** One wording for the two places a missing key surfaces. */
    private function noApiKey(): LocalizedException
    {
        return new LocalizedException(
            __('API key is not known. Go to the settings in the backoffice to create an API key. Fill the API key in the settings.')
        );
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
        $apiKey = (string) $this->config->getGeneralConfig('api/key', $storeId);

        return '' === $apiKey ? null : $apiKey;
    }

    /**
     * Group MyParcel shipment ids by each order's own resolved key.
     * A store without a key is skipped, never lent another store's key.
     *
     * @param iterable<\Magento\Sales\Model\Order\Shipment>                  $shipments
     * @param array<int,\Magento\Sales\Model\Order\Shipment\Track[]>          $tracksByShipmentId keyed by shipment
     *        id, read fresh by the caller: a Shipment caches its own tracks privately and would
     *        answer with the tracks as they were before this run wrote its shipment ids
     *
     * @return array<string,int[]>
     */
    public function myParcelShipmentIdsByApiKey(iterable $shipments, array $tracksByShipmentId): array
    {
        $grouped = [];

        foreach ($shipments as $shipment) {
            $apiKey = $this->apiKeyForStoreOrNull((int) $shipment->getStoreId());

            if (null === $apiKey) {
                continue;
            }

            foreach ($tracksByShipmentId[(int) $shipment->getId()] ?? [] as $track) {
                $myParcelShipmentId = (int) $track->getData('myparcel_consignment_id');

                if (0 < $myParcelShipmentId) {
                    // Keyed, not appended: a multicollo's colli share the parent's id until the
                    // query response names them, and asking for the same shipment twice is waste.
                    $grouped[$apiKey][$myParcelShipmentId] = $myParcelShipmentId;
                }
            }
        }

        return array_map('array_values', $grouped);
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
            ?? ($this->clients[$apiKey] = ShipmentApiFactory::make(
                $apiKey,
                null,
                $this->userAgent->header(),
                Config::API_FEATURE_FLAGS
            ));
    }
}
