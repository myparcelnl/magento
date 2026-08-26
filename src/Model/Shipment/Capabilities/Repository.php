<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Cache\Type\Capabilities as CapabilitiesCache;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;
use Throwable;

/**
 * Cache-aside access to capability answers, scoped to the API key of a given store.
 *
 * The only class consumers touch. Two rules it must keep (FR-000010):
 *
 * - Fail open. Any failure — no API key, a transport error, a 500, an undecodable body — returns
 *   CapabilitySet::permissive() rather than throwing. A capability lookup must never stop a label
 *   being created, and a store with no key must still render its admin form; export fails loudly
 *   on its own path instead.
 * - Serve stale. Entries are written with no expiry and removed only by cache:clean, an API key
 *   change or a settings import, so a failed refresh finds the previous answer still there. That is
 *   the whole mechanism; there is no second store.
 */
class Repository
{
    private const CACHE_ID_PREFIX = 'myparcel_caps_';

    private Client            $client;
    private CapabilitiesCache $cache;
    private Config            $config;
    private Fingerprint       $fingerprint;

    /** @var array<string,CapabilitySet> per-request memo, so one page render decodes once */
    private array $memo = [];

    public function __construct(
        Client            $client,
        CapabilitiesCache $cache,
        Config            $config,
        Fingerprint       $fingerprint
    )
    {
        $this->client      = $client;
        $this->cache       = $cache;
        $this->config      = $config;
        $this->fingerprint = $fingerprint;
    }

    /**
     * @param int|null $storeId resolves the API key at that store's scope; never an ambient store
     */
    public function forStore(?int $storeId, CapabilitiesRequest $request): CapabilitySet
    {
        $apiKey = (string) $this->config->getGeneralConfig('api/key', $storeId);

        if ('' === $apiKey) {
            Logger::warning(sprintf(
                'No MyParcel API key for store %s; capabilities unavailable, offering everything.',
                null === $storeId ? 'default' : (string) $storeId
            ));

            return CapabilitySet::permissive();
        }

        return $this->forApiKey($apiKey, $request);
    }

    public function forApiKey(string $apiKey, CapabilitiesRequest $request): CapabilitySet
    {
        try {
            $body = $this->client->serialize($request);
        } catch (Throwable $e) {
            $this->logFailure($apiKey, 'could not build the request: ' . $e->getMessage());

            return CapabilitySet::permissive();
        }

        $cacheId = self::CACHE_ID_PREFIX . $this->fingerprint->of($apiKey . '|' . $body);

        if (isset($this->memo[$cacheId])) {
            return $this->memo[$cacheId];
        }

        $cached = $this->cache->load($cacheId);

        if (is_string($cached) && '' !== $cached) {
            $results = json_decode($cached, true);

            if (is_array($results)) {
                return $this->memo[$cacheId] = CapabilitySet::fromApiResults($results);
            }
        }

        try {
            $results = $this->client->send($apiKey, $body);
        } catch (Throwable $e) {
            $this->logFailure($apiKey, $e->getMessage());

            return $this->memo[$cacheId] = CapabilitySet::permissive();
        }

        $this->cache->save((string) json_encode($results), $cacheId, [], null);

        $set = CapabilitySet::fromApiResults($results);
        $this->logUnknownValues($set);

        return $this->memo[$cacheId] = $set;
    }

    /**
     * Values the module could not translate. Logged once per fetch rather than per read, and at
     * notice, because this is the early-warning signal that the module needs updating rather than
     * something wrong right now.
     */
    private function logUnknownValues(CapabilitySet $set): void
    {
        foreach ($set->unknownValues() as $kind => $values) {
            if ($values) {
                Logger::notice(sprintf(
                    'Capabilities reported %s value(s) this module does not know: %s',
                    $kind,
                    implode(', ', $values)
                ));
            }
        }
    }

    /** The key is fingerprinted and truncated: enough to correlate lines, never the key itself. */
    private function logFailure(string $apiKey, string $reason): void
    {
        Logger::warning(sprintf(
            'Capabilities lookup failed for account %s: %s. Offering everything instead.',
            substr($this->fingerprint->of($apiKey), 0, Fingerprint::LABEL_LENGTH),
            $reason
        ));
    }
}
