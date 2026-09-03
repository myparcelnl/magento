<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order\Shipment;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\ShipmentOptionsResolver;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentCustomsDeclaration;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentCustomsDeclarationItem;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesMoney;

/**
 * Builds a v11 RefShipmentCustomsDeclaration for a ROW shipment. Stateless; shared across a batch.
 *
 * Deliberately separate from Helper\CustomsDeclarationFromOrder, which serves the Order v1
 * fulfilment path — a different API (DR-22).
 *
 * Two traps this class exists to contain: country must go through the item constructor, because the
 * generated enum for setCountry() lists only '' and throws on every real country — which also makes
 * listInvalidProperties() report a false positive here; and descriptions must be truncated before
 * setting, because setDescription() throws where the old path truncated silently.
 */
class CustomsDeclarationBuilder
{
    /** Sent by the legacy encoder for every shipment; the module never chose another value. */
    private const CONTENTS_COMMERCIAL_GOODS = 1;

    private const MAX_DESCRIPTION_LENGTH = 50;
    private const MAX_ITEMS              = 100;
    private const MAX_AMOUNT             = 99999;

    /** The HS code attribute's own cap; the API declares no maximum of its own. */
    private const MAX_CLASSIFICATION_LENGTH = 18;

    private ObjectManagerInterface $objectManager;
    private Config                 $config;
    private Weight                 $weight;

    /** The `myparcel_classification` EAV attribute id; the same for every product, so fetched once. */
    private ?string $classificationAttributeId = null;

    public function __construct(ObjectManagerInterface $objectManager, Config $config, Weight $weight)
    {
        $this->objectManager = $objectManager;
        $this->config        = $config;
        $this->weight        = $weight;
    }

    /**
     * One item per shipped item — the legacy path looped both getData('items') and getItems() and
     * added every item twice (FR-000006). Product data is fetched in two batch queries, not per item.
     *
     * @throws \RuntimeException when the shipment carries no item, or more than the API accepts
     */
    public function build(Shipment $shipment, int $totalWeightInGrams, string $invoice): RefShipmentCustomsDeclaration
    {
        $shipmentItems = [];

        foreach ($shipment->getItems() as $item) {
            $shipmentItems[] = $item;
        }

        if (! $shipmentItems) {
            throw new \RuntimeException('A shipment to a country outside the EU needs at least one customs item');
        }

        if (self::MAX_ITEMS < count($shipmentItems)) {
            throw new \RuntimeException(
                sprintf('A customs declaration takes at most %d items, this shipment has %d', self::MAX_ITEMS, count($shipmentItems))
            );
        }

        $productIds      = array_map(static fn($item): int => (int) $item->getProductId(), $shipmentItems);
        $classifications = $this->classificationsFor($productIds);
        $countries       = $this->countriesOfOriginFor($productIds);

        $items = [];

        foreach ($shipmentItems as $item) {
            $productId = (int) $item->getProductId();

            $items[] = $this->buildItem(
                (string) $item->getName(),
                (int) $item->getQty(),
                (float) $item->getWeight(),
                (float) $item->getPrice(),
                (string) ($classifications[$productId] ?? ''),
                (string) ($countries[$productId] ?? '')
            );
        }

        return (new RefShipmentCustomsDeclaration())
            ->setContents(self::CONTENTS_COMMERCIAL_GOODS)
            ->setWeight($totalWeightInGrams)
            ->setInvoice($invoice)
            ->setItems($items);
    }

    private function buildItem(
        string $name,
        int    $qty,
        float  $unitWeight,
        float  $unitPrice,
        string $classification,
        string $countryOfOrigin
    ): RefShipmentCustomsDeclarationItem
    {
        $amount = max(1, min(self::MAX_AMOUNT, $qty));

        // A zero-gram customs item is refused by the API, so an item with no weight set counts as 1.
        $weightInGrams = $this->weight->convertToGrams($unitWeight * $qty) ?: 1;

        $itemValue = (new RefTypesMoney())
            ->setCurrency(RefTypesMoney::CURRENCY_EUR)
            ->setAmount(DeliveryCosts::getPriceInCents($unitPrice));

        return (new RefShipmentCustomsDeclarationItem(['country' => $countryOfOrigin]))
            ->setDescription(mb_substr($name, 0, self::MAX_DESCRIPTION_LENGTH))
            ->setAmount($amount)
            ->setWeight($weightInGrams)
            ->setItemValue($itemValue)
            ->setClassification(substr($classification, 0, self::MAX_CLASSIFICATION_LENGTH));
    }

    /**
     * HS codes for all products at once, from the varchar table: a string of up to 18 characters,
     * digits and dots (6109.10). The int table it moved from dropped leading zeroes and dots.
     *
     * @param int[] $productIds
     *
     * @return array<int,string> product id => HS code
     */
    private function classificationsFor(array $productIds): array
    {
        $resource   = $this->objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();

        if (null === $this->classificationAttributeId) {
            $this->classificationAttributeId = ShipmentOptionsResolver::getAttributeId(
                $connection,
                $resource->getTableName('eav_attribute'),
                'classification'
            );
        }

        $select = $connection->select()
                             ->from($resource->getTableName('catalog_product_entity_varchar'), ['entity_id', 'value'])
                             ->where('attribute_id = ?', $this->classificationAttributeId)
                             ->where('entity_id IN (?)', $productIds);

        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * Product setting first, MyParcel setting second — resolved per product, fetched as one query.
     *
     * @param int[] $productIds
     *
     * @return array<int,string> product id => ISO country
     */
    private function countriesOfOriginFor(array $productIds): array
    {
        $fallback = (string) $this->config->getGeneralConfig('print/country_of_origin');

        /** @var ProductCollection $collection */
        $collection = $this->objectManager->create(ProductCollection::class);
        $collection->addIdFilter($productIds)
                   ->addAttributeToSelect('country_of_manufacture');

        $countries = [];

        foreach ($collection->getItems() as $product) {
            $countries[(int) $product->getId()] = (string) ($product->getCountryOfManufacture() ?: $fallback);
        }

        // A product that no longer exists still needs a country on its customs item.
        return $countries + array_fill_keys($productIds, $fallback);
    }
}
