<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ModelInterface;
use MyParcelNL\Sdk\Model\Shipment\Shipment;

/**
 * Local pre-flight check of a Shipment and its nested models.
 *
 * Needed because Shipment::valid() does not recurse: a missing recipient.cc would reach the API and
 * come back as a batch-level error instead of a per-order message. Customs is deliberately absent —
 * its items report a false invalid-country for every country we send (see CustomsDeclarationBuilder).
 */
class ShipmentValidator
{
    /** @return string[] empty when the shipment is safe to send */
    public function problemsWith(Shipment $shipment): array
    {
        $problems = $shipment->listInvalidProperties();

        foreach ($this->nestedModels($shipment) as $label => $model) {
            foreach ($model->listInvalidProperties() as $problem) {
                $problems[] = $label . ': ' . $problem;
            }
        }

        return $problems;
    }

    /** @return array<string,ModelInterface> */
    private function nestedModels(Shipment $shipment): array
    {
        $candidates = [
            'recipient'           => $shipment->getRecipient(),
            'options'             => $shipment->getOptions(),
            'physical properties' => $shipment->getPhysicalProperties(),
            'pickup'              => $shipment->getPickup(),
        ];

        return array_filter($candidates, static fn($model): bool => $model instanceof ModelInterface);
    }
}
