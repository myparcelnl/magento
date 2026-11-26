<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Capabilities;

use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

/**
 * Builds a capabilities request from module names, and remembers the answer per request.
 *
 * Without a package type, the API merges all package types of a carrier into one result with the
 * union of their options. Ask options, insurance and the collo maximum per package type.
 *
 * The memo is per instance: answeredPermissively() tells about what this instance asked. A caller
 * that reads it needs its own instance: etc/di.xml gives Block\Sales\NewShipment an unshared one.
 */
class CapabilitiesLookup
{
    private CapabilitiesProvider $provider;

    private ShipmentApiProvider $apiProvider;

    /** @var array<string,CapabilitySet> keyed by API key, country, package type and carrier */
    private array $answers = [];

    public function __construct(CapabilitiesProvider $provider, ShipmentApiProvider $apiProvider)
    {
        $this->provider    = $provider;
        $this->apiProvider = $apiProvider;
    }

    /**
     * Get the capabilities for this store and request. Without a package type, use the answer only
     * for the carriers and their package types. An empty country gets the permissive set.
     *
     * @param int|null $storeId resolves the API key at that store's scope; null means the current store
     */
    public function forRequest(
        ?int    $storeId,
        string  $country,
        ?string $packageType = null,
        ?string $carrier = null
    ): CapabilitySet
    {
        $apiKey = $this->apiProvider->apiKeyForStoreOrNull($storeId);

        if (null !== $apiKey) {
            return $this->forApiKeyRequest($apiKey, $country, $packageType, $carrier);
        }

        // Memoised like any answer, so answeredPermissively() sees it and the warning is logged once.
        $memoKey = 'store:' . $storeId;

        if (! isset($this->answers[$memoKey])) {
            Logger::warning(sprintf(
                'No MyParcel API key for store %s; capabilities unavailable, offering everything.',
                null === $storeId ? 'current' : (string) $storeId
            ));

            $this->answers[$memoKey] = CapabilitySet::permissive();
        }

        return $this->answers[$memoKey];
    }

    /**
     * Get the capabilities for this API key and request, once per set of inputs. The memo comes
     * before the request build, which costs a model and a hash.
     */
    public function forApiKeyRequest(
        string  $apiKey,
        string  $country,
        ?string $packageType = null,
        ?string $carrier = null
    ): CapabilitySet
    {
        if ('' === $apiKey) {
            return CapabilitySet::permissive();
        }

        $memoKey = $apiKey . '|' . $country . '|' . (string) $packageType . '|' . (string) $carrier;

        if (isset($this->answers[$memoKey])) {
            return $this->answers[$memoKey];
        }

        if ('' === $country) {
            return $this->answers[$memoKey] = CapabilitySet::permissive();
        }

        $request = CapabilitiesRequest::forCountry($country);

        if (null !== $packageType) {
            $v2 = PackageType::toV2Name($packageType);

            if (null === $v2) {
                // The API cannot be asked about this package type, so the answer is permissive.
                return $this->answers[$memoKey] = CapabilitySet::permissive();
            }

            $request = $request->withPackageType($v2);
        }

        if (null !== $carrier) {
            $v2Carrier = Carrier::toV2Name($carrier);

            if (null === $v2Carrier) {
                return $this->answers[$memoKey] = CapabilitySet::permissive();
            }

            $request = $request->withCarrier($v2Carrier);
        }

        return $this->answers[$memoKey] = $this->provider->forApiKey($apiKey, $request);
    }

    /** Check whether one of the answers of this instance was a fallback, not the account's own. */
    public function answeredPermissively(): bool
    {
        foreach ($this->answers as $set) {
            if ($set->isPermissive()) {
                return true;
            }
        }

        return false;
    }
}
