<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

/**
 * Builds a capabilities request from module names, and remembers the answer per request.
 *
 * Without a package type, the API merges all package types of a carrier into one result with the
 * union of their options. Ask options, insurance and the collo maximum per package type.
 *
 * The memo is per instance: answeredPermissively() tells about what this instance asked.
 */
class CapabilitiesLookup
{
    private Repository $repository;

    /** @var array<string,CapabilitySet> keyed by store, country and package type */
    private array $answers = [];

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get the capabilities for this store and request. Without a package type, use the answer only
     * for the carriers and their package types. An empty country gets the permissive set.
     */
    public function forRequest(
        ?int    $storeId,
        string  $country,
        ?string $packageType = null,
        ?string $carrier = null
    ): CapabilitySet
    {
        return $this->answer(
            'store:' . $storeId,
            $country,
            $packageType,
            $carrier,
            function (CapabilitiesRequest $request) use ($storeId): CapabilitySet {
                return $this->repository->forStore($storeId, $request);
            }
        );
    }

    /**
     * Get the capabilities for this API key and request, for the export, which groups by key.
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

        return $this->answer(
            'key:' . $apiKey,
            $country,
            $packageType,
            $carrier,
            function (CapabilitiesRequest $request) use ($apiKey): CapabilitySet {
                return $this->repository->forApiKey($apiKey, $request);
            }
        );
    }

    /**
     * Build the request and ask it, once per set of inputs. The memo comes before the build, which
     * costs a model and a hash.
     *
     * @param callable(CapabilitiesRequest): CapabilitySet $ask
     */
    private function answer(
        string   $scope,
        string   $country,
        ?string  $packageType,
        ?string  $carrier,
        callable $ask
    ): CapabilitySet
    {
        $key = $scope . '|' . $country . '|' . (string) $packageType . '|' . (string) $carrier;

        if (isset($this->answers[$key])) {
            return $this->answers[$key];
        }

        if ('' === $country) {
            return $this->answers[$key] = CapabilitySet::permissive();
        }

        $request = CapabilitiesRequest::forCountry($country);

        if (null !== $packageType) {
            $v2 = PackageType::toV2Name($packageType);

            if (null === $v2) {
                // The API cannot be asked about it, and the broad answer would offer too much.
                return $this->answers[$key] = CapabilitySet::permissive();
            }

            $request = $request->withPackageType($v2);
        }

        if (null !== $carrier) {
            $v2Carrier = Carrier::toV2Name($carrier);

            if (null === $v2Carrier) {
                return $this->answers[$key] = CapabilitySet::permissive();
            }

            $request = $request->withCarrier($v2Carrier);
        }

        return $this->answers[$key] = $ask($request);
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
