<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use Magento\Framework\Lock\LockManagerInterface;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Cache\Type\Capabilities as CapabilitiesCache;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;
use Throwable;

/**
 * Cache-aside access to capability answers, scoped to the API key of a given store.
 *
 * The only class consumers touch. Three rules it must keep:
 *
 * - Fail open. Any failure — no API key, a transport error, a 429, a 500, an undecodable body —
 *   returns CapabilitySet::permissive() rather than throwing. A capability lookup must never stop a
 *   label being created, and a store with no key must still render its admin form; export fails
 *   loudly on its own path instead.
 * - Serve stale. Successful entries are written with no expiry and removed only by cache:clean, an
 *   API key change or a settings import, so a failed refresh finds the previous answer still there.
 *   StoredAnswers keeps a copy outside the cache, so a shape that cannot be fetched gets its last
 *   answer even after the cache was emptied, and permissive only when it was never answered.
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
     * Successful entries never expire, but a failure must: without it, an admin form that fans out
     * over several package types repeats the whole burst on every reload, which is exactly the load
     * a 429 asks us to stop applying.
     */
    private const FAILURE_LIFETIME_SECONDS = 60;

    private Client              $client;
    private CapabilitiesCache   $cache;
    private Config              $config;
    private Fingerprint         $fingerprint;
    private LockManagerInterface $lockManager;
    private StoredAnswers        $storedAnswers;

    /** @var array<string,CapabilitySet> per-request memo, so one page render decodes once */
    private array $memo = [];

    public function __construct(
        Client               $client,
        CapabilitiesCache    $cache,
        Config               $config,
        Fingerprint          $fingerprint,
        LockManagerInterface $lockManager,
        StoredAnswers        $storedAnswers
    )
    {
        $this->client      = $client;
        $this->cache       = $cache;
        $this->config      = $config;
        $this->fingerprint = $fingerprint;
        $this->lockManager = $lockManager;
        $this->storedAnswers = $storedAnswers;
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
            return $this->failed(
                $apiKey,
                'could not build the request: ' . $e->getMessage(),
                CapabilitySet::permissive()
            );
        }

        $shape = $this->fingerprint->of($apiKey . '|' . $body);

        // The cache and the lock can throw too, and the first rule above covers them as well.
        try {
            return $this->lookup($apiKey, $body, $shape);
        } catch (Throwable $e) {
            return $this->failed($apiKey, $e->getMessage(), $this->stored($apiKey, $shape));
        }
    }

    private function lookup(string $apiKey, string $body, string $shape): CapabilitySet
    {
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
            return $this->memo[$cacheId] = $this->stored($apiKey, $shape);
        }

        // One fetch per shape at a time. After a deploy or cache:clean every concurrent checkout
        // misses the same shape at once, and without this each one calls out. Waiting is not the
        // alternative — that queues them all behind one request — so the rest answer from the
        // stored copy, exactly as they would if the fetch had failed.
        $lockName = self::CACHE_ID_PREFIX . 'fetch_' . $shape;

        // 0: try once and move on. Waiting is the thing being avoided.
        if (! $this->lockManager->lock($lockName, 0)) {
            return $this->memo[$cacheId] = $this->stored($apiKey, $shape);
        }

        try {
            $results = $this->client->send($apiKey, $body);
        } catch (Throwable $e) {
            $this->cache->save('1', $failureId, [], self::FAILURE_LIFETIME_SECONDS);

            return $this->memo[$cacheId] = $this->failed($apiKey, $e->getMessage(), $this->stored($apiKey, $shape));
        } finally {
            $this->lockManager->unlock($lockName);
        }

        $this->store($apiKey, $shape, $results);
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

    /** The last answer stored for this shape, or permissive when it was never answered. */
    private function stored(string $apiKey, string $shape): CapabilitySet
    {
        try {
            $results = $this->storedAnswers->load($apiKey, $shape);
        } catch (Throwable $e) {
            $results = null;
        }

        return null === $results ? CapabilitySet::permissive() : CapabilitySet::fromApiResults($results);
    }

    /**
     * Before the cache entry, so a cache backend that refuses the save still leaves the stored copy.
     * A failed write only costs the fallback, never the answer in hand.
     */
    private function store(string $apiKey, string $shape, array $results): void
    {
        try {
            $this->storedAnswers->save($apiKey, $shape, $results);
        } catch (Throwable $e) {
            Logger::warning(sprintf(
                'Could not store the capabilities answer for account %s: %s',
                $this->accountLabel($apiKey),
                $e->getMessage()
            ));
        }
    }

    /** Logged once per failure: the stored-answer reads on the other paths stay silent. */
    private function failed(string $apiKey, string $reason, CapabilitySet $served): CapabilitySet
    {
        Logger::warning(sprintf(
            'Capabilities lookup failed for account %s: %s. %s',
            $this->accountLabel($apiKey),
            $reason,
            $served->isPermissive() ? 'Offering everything instead.' : 'Serving the last stored answer.'
        ));

        return $served;
    }

    /** The key is fingerprinted and truncated: enough to correlate lines, never the key itself. */
    private function accountLabel(string $apiKey): string
    {
        return substr($this->fingerprint->of($apiKey), 0, Fingerprint::LABEL_LENGTH);
    }
}
