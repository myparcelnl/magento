<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Api\ShipmentApi;
use MyParcelNL\Sdk\Services\CoreApi\ShipmentApiFactory;

/**
 * The module's only call site for ShipmentApiFactory::make(), and the only place an API key is
 * turned into a client (TR-000006).
 *
 * It exists because an empty key does not fail in the SDK: ShipmentApiFactory::resolveApiKey()
 * falls back to getenv('API_KEY'), then API_KEY_NL, then API_KEY_BE, so a store with no key
 * configured silently ships to whatever account the environment names. The guard below has to run
 * before the factory, every time — which is only enforceable while there is one call site. A second
 * one reopens the hole.
 *
 * Clients are memoised per key so the six per-key services share one, as TR-000006 requires.
 */
class ShipmentApiProvider
{
    private Config $config;

    /** @var array<string,ShipmentApi> keyed by API key */
    private array $clients = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @throws LocalizedException when the store has no API key, naming nothing it could fall back to
     */
    public function apiKeyForStore(?int $storeId): string
    {
        $apiKey = $this->apiKeyForStoreOrNull($storeId);

        if (null === $apiKey) {
            throw new LocalizedException(
                __('API key is not known. Go to the settings in the backoffice to create an API key. Fill the API key in the settings.')
            );
        }

        return $apiKey;
    }

    /** The store's resolved key, or null — for callers that skip a keyless store rather than fail. */
    public function apiKeyForStoreOrNull(?int $storeId): ?string
    {
        $apiKey = (string) $this->config->getGeneralConfig('api/key', $storeId);

        return '' === $apiKey ? null : $apiKey;
    }

    /**
     * MyParcel shipment ids grouped by each order's own resolved key — the shape every per-key call
     * takes. A store without a key is skipped, never lent another store's key.
     *
     * @param iterable<\Magento\Sales\Model\Order\Shipment> $shipments
     *
     * @return array<string,int[]>
     */
    public function consignmentIdsByApiKey(iterable $shipments): array
    {
        $grouped = [];

        foreach ($shipments as $shipment) {
            $apiKey = $this->apiKeyForStoreOrNull((int) $shipment->getOrder()->getStoreId());

            if (null === $apiKey) {
                continue;
            }

            foreach ($shipment->getAllTracks() as $track) {
                $consignmentId = (int) $track->getData('myparcel_consignment_id');

                if (0 < $consignmentId) {
                    $grouped[$apiKey][] = $consignmentId;
                }
            }
        }

        return $grouped;
    }

    /**
     * @throws LocalizedException on an empty key, before the SDK factory can read the environment
     */
    public function clientFor(string $apiKey): ShipmentApi
    {
        if ('' === $apiKey) {
            throw new LocalizedException(
                __('API key is not known. Go to the settings in the backoffice to create an API key. Fill the API key in the settings.')
            );
        }

        return $this->clients[$apiKey] ?? ($this->clients[$apiKey] = ShipmentApiFactory::make($apiKey));
    }

    /** The proposition string every per-key service is tagged with, replacing MyParcelCollection::setUserAgents(). */
    public function userAgentVersion(): string
    {
        return $this->config->getVersion();
    }
}
