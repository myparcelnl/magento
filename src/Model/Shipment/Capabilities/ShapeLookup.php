<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

/**
 * Capabilities for one shipment shape, asked once per shape.
 *
 * Repository answers for a request; this builds the request and remembers the answer, which is what
 * the admin New Shipment block and the checkout were each writing out in full.
 *
 * **A package-type-agnostic response is a superset, not a matrix.** The endpoint answers for the
 * shipment shape it is given, and `packageType` is singular. Ask without one and the API groups
 * every package type of a carrier into a single result carrying the union of their options — so a
 * mailbox would inherit the options of a package. Options, insurance and the collo maximum
 * therefore have to be asked per package type; only the carrier and package-type lists come from
 * the broad call.
 *
 * The memo and answeredPermissively() are per instance, so each consumer holds its own and the
 * answer means "what this render asked".
 */
class ShapeLookup
{
    private Repository $repository;

    /** @var array<string,CapabilitySet> keyed by store, country and package type */
    private array $answers = [];

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Pass null for the shape-agnostic question — which carriers, and which package types each has.
     *
     * An order with no shipping address has no country to ask about, so it gets the permissive set
     * rather than a country we invented for it.
     */
    public function forShape(
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
     * The same question against one account's key, for a path that resolved the key itself rather
     * than a store — the export, which groups by key.
     */
    public function forApiKeyShape(
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
     * Builds the request once per shape and remembers the answer.
     *
     * The memo is consulted before the request is built: Repository fingerprints the *serialized*
     * body, so reaching it means a typed-model build and a hash for a question already answered.
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
                // Nothing to ask about: an unmappable package type cannot be sent, and the broad
                // answer would over-report. Withhold rather than guess.
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

    /**
     * Whether any answer this instance gave was a fallback rather than the account's own.
     *
     * A partial failure is the case that matters: some package types answered, others fell back and
     * are therefore offering everything.
     */
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
