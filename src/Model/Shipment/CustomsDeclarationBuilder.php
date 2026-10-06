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
 * Deliberately separate from Helper\CustomsDeclarationFromOrder, which serves the Order v1
 * fulfilment path, a different API.
 *
 * Two traps this class exists to contain: country must go through the item constructor, because the
 * generated enum for setCountry() lists only '' and throws on every real country, which also makes
 * listInvalidProperties() report a false positive here; and descriptions must be truncated before
 * setting, because setDescription() throws where the old path truncated silently.
 */
class CustomsDeclarationBuilder
{
    private CustomsItems $items;

    public function __construct(ObjectManagerInterface $objectManager, Config $config, Weight $weight)
    {
        $this->items = new CustomsItems($objectManager, $config, $weight);
    }

    /**
     * One item per shipped item: the legacy path looped both getData('items') and getItems() and
     * added every item twice. Product data is fetched in two batch queries, not per item.
     *
     * @throws \RuntimeException when the SDK refuses the declaration, for example no item or more than 100
     */
    public function build(Shipment $shipment, int $totalWeightInGrams, string $invoice): RefShipmentCustomsDeclaration
    {
        $shipmentItems = [];

        foreach ($shipment->getItems() as $item) {
            $shipmentItems[] = $item;
        }

        $productIds      = array_map(static fn($item): int => (int) $item->getProductId(), $shipmentItems);
        $classifications = $this->items->classificationsFor($productIds);
        $countries       = $this->items->countriesOfOriginFor($productIds, (int) $shipment->getStoreId());

        $items = [];

        foreach ($shipmentItems as $item) {
            $productId = (int) $item->getProductId();

            $items[] = $this->buildItem(
                (string) $item->getName(),
                $item->getSku(),
                (int) $item->getQty(),
                (float) $item->getWeight(),
                (float) $item->getPrice(),
                (string) ($classifications[$productId] ?? ''),
                (string) ($countries[$productId] ?? '')
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

    private function buildItem(
        string  $name,
        ?string $sku,
        int     $qty,
        float   $unitWeight,
        float   $unitPrice,
        string  $classification,
        string  $countryOfOrigin
    ): RefShipmentCustomsDeclarationItem
    {
        // A zero-quantity line is not an error, it is a line the API refuses.
        $amount = max(1, $qty);

        $itemValue = (new RefTypesMoney())
            ->setCurrency(RefTypesMoney::CURRENCY_EUR)
            ->setAmount($this->items->lineValueInCents($unitPrice, (float) $amount));

        try {
            return (new RefShipmentCustomsDeclarationItem(['country' => $countryOfOrigin]))
                ->setDescription($this->items->description($name))
                ->setAmount($amount)
                ->setWeight($this->items->lineWeightInGrams($unitWeight, (float) $amount))
                ->setItemValue($itemValue)
                ->setClassification($this->items->classification($classification));
        } catch (\InvalidArgumentException $e) {
            throw CustomsItems::refused($name, $sku, $e);
        }
    }


}
