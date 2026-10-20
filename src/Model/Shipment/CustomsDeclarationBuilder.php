<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order\Shipment;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentCustomsDeclaration;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentCustomsDeclarationItem;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesMoney;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentDefsCustomsShipmentType;

/**
 * Builds a v11 RefShipmentCustomsDeclaration for a ROW shipment. Stateless; shared across a batch.
 *
 * Pass the country through the item constructor: the generated setCountry() enum lists only '' and
 * throws on every real country, and listInvalidProperties() reports a false positive.
 * Truncate a description before setDescription(), which throws above its limit.
 */
class CustomsDeclarationBuilder
{
    private CustomsItems $items;

    public function __construct(ObjectManagerInterface $objectManager, Config $config, Weight $weight)
    {
        $this->items = new CustomsItems($objectManager, $config, $weight);
    }

    /**
     * Build the declaration with one item per shipped item.
     *
     * getAllItems() leaves out what ShipmentFactory marked deleted: before the save, the shipment
     * still carries a configurable's variant as an item of its own.
     *
     * @throws \RuntimeException when the SDK refuses the declaration, for example no item or more than 100
     */
    public function build(Shipment $shipment, int $totalWeightInGrams, string $invoice): RefShipmentCustomsDeclaration
    {
        $shipmentItems = [];

        foreach ($shipment->getAllItems() as $item) {
            $shipmentItems[] = $item;
        }

        $customsData = $this->items->customsDataFor(
            array_map([self::class, 'productIdsOf'], $shipmentItems),
            (int) $shipment->getStoreId()
        );

        $items = [];

        foreach ($shipmentItems as $line => $item) {
            $items[] = $this->buildItem(
                (string) $item->getName(),
                $item->getSku(),
                (int) $item->getQty(),
                (float) $item->getWeight(),
                (float) $item->getPrice(),
                $customsData[$line]['classification'],
                $customsData[$line]['country'],
                $customsData[$line]['external_product_reference']
            );
        }

        try {
            return (new RefShipmentCustomsDeclaration())
                ->setContents(ShipmentDefsCustomsShipmentType::COMMERCIAL_GOODS)
                ->setWeight($totalWeightInGrams)
                ->setInvoice($invoice)
                ->setItems($items);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('Customs declaration: ' . $e->getMessage(), 0, $e);
        }
    }

    /** A configurable item ships as its parent; the variant's own customs data comes first. */
    private static function productIdsOf($shipmentItem): array
    {
        $orderItem = $shipmentItem->getOrderItem();
        $variants  = $orderItem && CustomsItems::CONFIGURABLE === $orderItem->getProductType()
            ? (array) $orderItem->getChildrenItems()
            : [];

        return array_merge(
            array_map(static fn($child): int => (int) $child->getProductId(), $variants),
            [(int) $shipmentItem->getProductId()]
        );
    }

    private function buildItem(
        string  $name,
        ?string $sku,
        int     $qty,
        float   $unitWeight,
        float   $unitPrice,
        string  $classification,
        string  $countryOfOrigin,
        ?string $externalProductReference
    ): RefShipmentCustomsDeclarationItem
    {
        // A zero-quantity line is not an error, it is a line the API refuses.
        $amount = max(1, $qty);

        $itemValue = (new RefTypesMoney())
            ->setCurrency(RefTypesMoney::CURRENCY_EUR)
            ->setAmount($this->items->lineValueInCents($unitPrice, (float) $amount));

        try {
            $item = (new RefShipmentCustomsDeclarationItem(['country' => $countryOfOrigin]))
                ->setDescription($this->items->description($name))
                ->setAmount($amount)
                ->setWeight($this->items->lineWeightInGrams($unitWeight, (float) $amount))
                ->setItemValue($itemValue)
                ->setClassification($this->items->classification($classification));

            // Setting null marks the key as sent, so a missing value is not set at all.
            if (null !== $externalProductReference) {
                $item->setExternalProductReference($externalProductReference);
            }
        } catch (\InvalidArgumentException $e) {
            throw CustomsItems::refused($name, $sku, $e);
        }

        return $item;
    }


}
