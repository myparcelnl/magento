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
            'address'         => (object) [
                'street'     => $pickupLocation->getStreet(),
                'number'     => $pickupLocation->getNumber(),
                'postalCode' => $pickupLocation->getPostalCode(),
                'city'       => $pickupLocation->getCity(),
                'cc'         => $pickupLocation->getCountry(),
            ],
        ];
    }
}
