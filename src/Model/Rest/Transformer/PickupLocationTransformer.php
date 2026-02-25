<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractPickupLocationAdapter;

class PickupLocationTransformer
{
    public function transform(?AbstractPickupLocationAdapter $pickupLocation): ?\stdClass
    {
        if ($pickupLocation === null) {
            return null;
        }

        return (object) [
            'locationCode'    => $pickupLocation->getLocationCode(),
            'locationName'    => $pickupLocation->getLocationName(),
            'retailNetworkId' => $pickupLocation->getRetailNetworkId(),
            'type'            => null,
            'address'         => (object) [
                'street'       => $pickupLocation->getStreet(),
                'number'       => $pickupLocation->getNumber(),
                'numberSuffix' => null,
                'postalCode'   => $pickupLocation->getPostalCode(),
                'boxNumber'    => null,
                'city'         => $pickupLocation->getCity(),
                'cc'           => $pickupLocation->getCountry(),
                'state'        => null,
                'region'       => null,
            ],
        ];
    }
}
