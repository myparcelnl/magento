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
 * Gets capabilities through the cache, per API key and request. Three rules:
 *
 * - Never throw. A failure gives the last stored answer, else CapabilitySet::permissive().
 * - A request whose refresh fails gets its last answer from StoredAnswers.
 * - A failed request is not asked again for FAILURE_LIFETIME_SECONDS. A good cached answer wins.
 */
class Repository
{
    private const CACHE_ID_PREFIX   = 'myparcel_capabilities_';
    private const FAILURE_ID_PREFIX = 'myparcel_capabilities_failed_';

    /** How long a good answer is cached: the longest a capabilities change takes to reach the shop. */
    private const SUCCESS_LIFETIME_SECONDS = 10800;

    /** How long a failed request is not asked again, so a reload does not repeat the burst. */
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
     * Get the capabilities for the API key of this store and this request.
     *
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

    /**
     * Get the capabilities for this request, from the cache or the API. When the API fails, or
     * another request is fetching the same answer, use the last stored answer, else a permissive set.
     */
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

        $requestKey = $this->fingerprint->of($apiKey . '|' . $body);

        // The cache and the lock can throw too, and the first rule above covers them as well.
        try {
            return $this->lookup($apiKey, $body, $requestKey);
        } catch (Throwable $e) {
            return $this->failed($apiKey, $e->getMessage(), $this->stored($apiKey, $requestKey));
        }
    }

    private function lookup(string $apiKey, string $body, string $requestKey): CapabilitySet
    {
        $cacheId   = self::CACHE_ID_PREFIX . $requestKey;
        $failureId = self::FAILURE_ID_PREFIX . $requestKey;

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
            return $this->memo[$cacheId] = $this->stored($apiKey, $requestKey);
        }

        // One fetch per request at a time: after a cache:clean, every checkout misses at once. The
        // others use the stored answer and do not wait.
        $lockName = self::CACHE_ID_PREFIX . 'fetch_' . $requestKey;

        // 0: try once and move on. Waiting is the thing being avoided.
        if (! $this->lockManager->lock($lockName, 0)) {
            return $this->memo[$cacheId] = $this->stored($apiKey, $requestKey);
        }

        try {
            $results = $this->client->send($apiKey, $body);
        } catch (Throwable $e) {
            $this->cache->save('1', $failureId, [], self::FAILURE_LIFETIME_SECONDS);

            return $this->memo[$cacheId] = $this->failed($apiKey, $e->getMessage(), $this->stored($apiKey, $requestKey));
        } finally {
            $this->lockManager->unlock($lockName);
        }

        $this->store($apiKey, $requestKey, $results);
        $this->cache->save((string) json_encode($results), $cacheId, [], self::SUCCESS_LIFETIME_SECONDS);

        $set = CapabilitySet::fromApiResults($results);
        $this->logUnknownValues($set);

        return $this->memo[$cacheId] = $set;
    }

    /** Log the values the module does not know, once per fetch, as a sign that it needs an update. */
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

    /** Get the last stored answer for this request, or permissive when it was never answered. */
    private function stored(string $apiKey, string $requestKey): CapabilitySet
    {
        try {
            $results = $this->storedAnswers->load($apiKey, $requestKey);
        } catch (Throwable $e) {
            $results = null;
        }

        return null === $results ? CapabilitySet::permissive() : CapabilitySet::fromApiResults($results);
    }

    /** Store the answer before the cache entry, so a cache that refuses the save keeps the copy. */
    private function store(string $apiKey, string $requestKey, array $results): void
    {
        try {
            $this->storedAnswers->save($apiKey, $requestKey, $results);
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
