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
 * The only class consumers touch. Three rules it must keep (FR-000010):
 *
 * - Fail open. Any failure — no API key, a transport error, a 429, a 500, an undecodable body —
 *   returns CapabilitySet::permissive() rather than throwing. A capability lookup must never stop a
 *   label being created, and a store with no key must still render its admin form; export fails
 *   loudly on its own path instead.
 * - Serve stale. Successful entries are written with no expiry and removed only by cache:clean, an
 *   API key change or a settings import, so a failed refresh finds the previous answer still there.
 * - Do not hammer a failing endpoint. A shape that failed is remembered as failed for
 *   FAILURE_LIFETIME_SECONDS, so a reload does not repeat the burst. Checked *after* the success
 *   entry, never before it, so a previous good answer always beats a recent failure.
 */
class Repository
{
    private const CACHE_ID_PREFIX   = 'myparcel_capabilities_';
    private const FAILURE_ID_PREFIX = 'myparcel_capabilities_failed_';

    /**
     * How long a failed shape is remembered as failed.
     *
     * The one place a lifetime belongs. Successful entries never expire, but a failure must:
     * without it, an admin form that fans out over several package types repeats the whole burst on
     * every reload, which is exactly the load a 429 asks us to stop applying. Short enough that a
     * merchant is not left on permissive answers after an incident passes.
     */
    private const FAILURE_LIFETIME_SECONDS = 60;

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

        $shape     = $this->fingerprint->of($apiKey . '|' . $body);
        $cacheId   = self::CACHE_ID_PREFIX . $shape;
        $failureId = self::FAILURE_ID_PREFIX . $shape;

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

        if (false !== $this->cache->load($failureId)) {
            // Asked recently and it failed. Do not ask again yet.
            return $this->memo[$cacheId] = CapabilitySet::permissive();
        }

        try {
            $results = $this->client->send($apiKey, $body);
        } catch (Throwable $e) {
            $this->logFailure($apiKey, $e->getMessage());
            $this->cache->save('1', $failureId, [], self::FAILURE_LIFETIME_SECONDS);

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
