<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractShipmentOptionsAdapter;
use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\ShipmentOptions as OrderApiShipmentOptions;

class ShipmentOptionsTransformer
{
    /**
     * Map of adapter boolean getter → Order API shipment-option snake_case key.
     * The camelCase API field name is resolved via OrderApiShipmentOptions::attributeMap()
     * so field renames in the SDK propagate automatically.
     */
    private const BOOLEAN_GETTER_TO_ORDER_API_KEY = [
        'hasAgeCheck'        => 'requires_age_verification',
        'hasSignature'       => 'requires_signature',
        'hasOnlyRecipient'   => 'recipient_only_delivery',
        'hasLargeFormat'     => 'oversized_package',
        'isReturn'           => 'print_return_label_at_drop_off',
        'hasHideSender'      => 'hide_sender',
        'isPriorityDelivery' => 'priority_delivery',
        'hasReceiptCode'     => 'requires_receipt_code',
        'isSameDayDelivery'  => 'same_day_delivery',
        'hasCollect'         => 'scheduled_collection',
    ];

    public function transform(?AbstractShipmentOptionsAdapter $shipmentOptions): ?\stdClass
    {
        if ($shipmentOptions === null) {
            return null;
        }

        $attributeMap = OrderApiShipmentOptions::attributeMap();
        $result = new \stdClass();

        foreach (self::BOOLEAN_GETTER_TO_ORDER_API_KEY as $getter => $snakeKey) {
            if ($shipmentOptions->$getter() !== true) {
                continue;
            }

            $apiField = $this->resolveApiField($snakeKey, $attributeMap);
            if ($apiField === null) {
                continue;
            }

            $result->$apiField = new \stdClass();
        }

        $insurance = $shipmentOptions->getInsurance();
        if ($insurance !== null && $insurance > 0) {
            $insuranceField = $this->resolveApiField('insurance', $attributeMap);
            if ($insuranceField !== null) {
                $result->$insuranceField = (object) [
                    'amount'   => $insurance * 1000000,
                    'currency' => 'EUR',
                ];
            }
        }

        $labelDescription = $shipmentOptions->getLabelDescription();
        if ($labelDescription !== null && $labelDescription !== '') {
            $labelField = $this->resolveApiField('custom_label_text', $attributeMap);
            if ($labelField !== null) {
                $result->$labelField = (object) [
                    'text' => $labelDescription,
                ];
            }
        }

        if ((array) $result === []) {
            return null;
        }

        return $result;
    }

    /**
     * Resolve the Order API camelCase field name for a given snake_case option key.
     *
     * 1. Direct lookup in attributeMap (snake_case key → camelCase value).
     * 2. camelCase fallback: if the computed camelCase equivalent is present
     *    as a value in attributeMap, use it (guards against the snake_case
     *    key having been renamed while the camelCase name is stable).
     */
    private function resolveApiField(string $snakeKey, array $attributeMap): ?string
    {
        if (isset($attributeMap[$snakeKey])) {
            return $attributeMap[$snakeKey];
        }

        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $snakeKey))));
        if (in_array($camel, $attributeMap, true)) {
            return $camel;
        }

        return null;
    }
}
