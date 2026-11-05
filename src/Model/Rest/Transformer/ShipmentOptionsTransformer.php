<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\ShipmentOptions as OrderApiShipmentOptions;
use MyParcelNL\Sdk\Services\Mapping\ShipmentOptionMapper;

class ShipmentOptionsTransformer
{
    /**
     * Map of adapter boolean getter → module option name. ShipmentOptionMapper gives the API key
     * and OrderApiShipmentOptions::attributeMap() its camelCase field, so SDK renames propagate.
     */
    private const BOOLEAN_GETTER_TO_OPTION = [
        'hasAgeCheck'         => ShipmentOption::AGE_CHECK,
        'hasSignature'        => ShipmentOption::SIGNATURE,
        'hasOnlyRecipient'    => ShipmentOption::ONLY_RECIPIENT,
        'hasLargeFormat'      => ShipmentOption::LARGE_FORMAT,
        'hasReturn'           => ShipmentOption::RETURN,
        'hasHideSender'       => ShipmentOption::HIDE_SENDER,
        'hasPriorityDelivery' => ShipmentOption::PRIORITY_DELIVERY,
        'hasReceiptCode'      => ShipmentOption::RECEIPT_CODE,
        'hasSameDayDelivery'  => ShipmentOption::SAME_DAY_DELIVERY,
        'hasCollect'          => ShipmentOption::COLLECT,
    ];

    public function transform(?ShipmentOptions $shipmentOptions): ?\stdClass
    {
        if ($shipmentOptions === null) {
            return null;
        }

        $attributeMap = OrderApiShipmentOptions::attributeMap();
        $optionMapper = new ShipmentOptionMapper();
        $result = new \stdClass();

        foreach (self::BOOLEAN_GETTER_TO_OPTION as $getter => $option) {
            if ($shipmentOptions->$getter() !== true) {
                continue;
            }

            $snakeKey = $optionMapper->v2PropertyFromName($option);
            $apiField = $snakeKey === null ? null : $this->resolveApiField($snakeKey, $attributeMap);
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
                    'amount'   => $insurance * 1000000, // Convert whole amount to integer micro format
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
