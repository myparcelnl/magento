<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractShipmentOptionsAdapter;

class ShipmentOptionsTransformer
{
    public function transform(?AbstractShipmentOptionsAdapter $shipmentOptions): ?\stdClass
    {
        if ($shipmentOptions === null) {
            return null;
        }

        $result = new \stdClass();

        $booleanMap = [
            'requiresAgeVerification'   => $shipmentOptions->hasAgeCheck(),
            'requiresSignature'         => $shipmentOptions->hasSignature(),
            'recipientOnlyDelivery'     => $shipmentOptions->hasOnlyRecipient(),
            'oversizedPackage'          => $shipmentOptions->hasLargeFormat(),
            'printReturnLabelAtDropOff' => $shipmentOptions->isReturn(),
            'hideSender'                => $shipmentOptions->hasHideSender(),
            'priorityDelivery'          => $shipmentOptions->isPriorityDelivery(),
            'requiresReceiptCode'       => $shipmentOptions->hasReceiptCode(),
            'sameDayDelivery'           => $shipmentOptions->isSameDayDelivery(),
            'scheduledCollection'       => $shipmentOptions->hasCollect(),
        ];

        foreach ($booleanMap as $apiField => $value) {
            if ($value === true) {
                $result->$apiField = new \stdClass();
            }
        }

        $insurance = $shipmentOptions->getInsurance();
        if ($insurance !== null && $insurance > 0) {
            $result->insurance = (object) [
                'amount'   => $insurance * 1000000,
                'currency' => 'EUR',
            ];
        }

        $labelDescription = $shipmentOptions->getLabelDescription();
        if ($labelDescription !== null && $labelDescription !== '') {
            $result->customLabelText = (object) [
                'text' => $labelDescription,
            ];
        }

        if ((array) $result === []) {
            return null;
        }

        return $result;
    }
}
