<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter\DeliveryOptions;

use InvalidArgumentException;

/**
 * The pickup point the customer chose at checkout.
 *
 * toArray() nests inside DeliveryOptions::toArray(), which is persisted and published, so its key
 * order is part of the contract.
 *
 * getRetailNetworkId() answers '' rather than null when unset. That is an SDK quirk the versioned
 * REST v1 contract depends on, so it is kept on purpose.
 */
final class PickupLocation
{
    /** @var string */
    private $locationName;

    /** @var string */
    private $locationCode;

    /** @var string|null */
    private $retailNetworkId;

    /** @var string */
    private $street;

    /** @var string */
    private $number;

    /** @var string */
    private $postalCode;

    /** @var string */
    private $city;

    /** @var string */
    private $cc;

    private function __construct(
        string $locationName,
        string $locationCode,
        ?string $retailNetworkId,
        string $street,
        string $number,
        string $postalCode,
        string $city,
        string $cc
    ) {
        $this->locationName    = $locationName;
        $this->locationCode    = $locationCode;
        $this->retailNetworkId = $retailNetworkId;
        $this->street          = $street;
        $this->number          = $number;
        $this->postalCode      = $postalCode;
        $this->city            = $city;
        $this->cc              = $cc;
    }

    /** Every field falls back to '', which the REST v1 contract depends on. */
    public static function fromCheckoutData(array $data): self
    {
        return new self(
            (string) ($data['location_name'] ?? ''),
            (string) ($data['location_code'] ?? ''),
            isset($data['retail_network_id']) ? (string) $data['retail_network_id'] : null,
            (string) ($data['street'] ?? ''),
            (string) ($data['number'] ?? ''),
            (string) ($data['postal_code'] ?? ''),
            (string) ($data['city'] ?? ''),
            (string) ($data['cc'] ?? '')
        );
    }

    /**
     * The old shape: pickup fields at the top level, and the name may be called 'location'.
     *
     * Throws naming the missing key. The SDK read these unguarded and failed later with a TypeError,
     * which is an Error rather than an Exception and so escaped some callers' catch blocks.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromLegacyCheckoutData(array $data): self
    {
        $name = $data['location_name'] ?? $data['location'] ?? null;

        if (null === $name) {
            throw new InvalidArgumentException('Legacy pickup location has neither location_name nor location');
        }

        foreach (['location_code', 'street', 'number', 'postal_code', 'city', 'cc'] as $required) {
            if (! isset($data[$required])) {
                throw new InvalidArgumentException("Legacy pickup location is missing '$required'");
            }
        }

        return new self(
            (string) $name,
            (string) $data['location_code'],
            isset($data['retail_network_id']) ? (string) $data['retail_network_id'] : null,
            (string) $data['street'],
            (string) $data['number'],
            (string) $data['postal_code'],
            (string) $data['city'],
            (string) $data['cc']
        );
    }

    public function getLocationName(): string
    {
        return $this->locationName;
    }

    public function getLocationCode(): string
    {
        return $this->locationCode;
    }

    public function getRetailNetworkId(): string
    {
        return $this->retailNetworkId ?? '';
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getCountry(): ?string
    {
        return $this->cc;
    }

    /** Key order is part of the persisted format. Do not rearrange. */
    public function toArray(): array
    {
        return [
            'location_name'     => $this->getLocationName(),
            'location_code'     => $this->getLocationCode(),
            'retail_network_id' => $this->getRetailNetworkId(),
            'street'            => $this->getStreet(),
            'number'            => $this->getNumber(),
            'postal_code'       => $this->getPostalCode(),
            'city'              => $this->getCity(),
            'cc'                => $this->getCountry(),
        ];
    }
}
