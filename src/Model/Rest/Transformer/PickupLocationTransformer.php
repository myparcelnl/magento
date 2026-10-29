<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Magento\Adapter\DeliveryOptions\PickupLocation;

class PickupLocationTransformer
{
    public function transform(?PickupLocation $pickupLocation): ?\stdClass
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
                'postalCode'   => $pickupLocation->getPostalCode(),
                'city'         => $pickupLocation->getCity(),
                'cc'           => $pickupLocation->getCountry(),
            ],
        ];
    }
}
