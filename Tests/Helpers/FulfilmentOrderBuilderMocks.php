<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Shipment\FulfilmentOrderBuilder;

/**
 * Fixtures for the PPS export path. The object manager and the config come from
 * ShipmentBuilderMocks, which both export builders share; what is added here is the
 * ScopeConfigInterface the order date reads and an order item carrying a product.
 */

/**
 * Drives the real constructor and the real build(). $apiKeyStoreId null means every store resolves
 * to the key, as an inherited default does; pass one to make only that store resolve.
 */
function createFulfilmentOrderBuilder(
    ?string $apiKey = 'test-api-key',
    ?int    $apiKeyStoreId = null,
    array   $configValues = ['print/weight_indication' => 'gram']
): FulfilmentOrderBuilder
{
    $objectManager = mockExportObjectManager(createExportConfig($apiKey, $apiKeyStoreId, $configValues));

    // No timezone configured, so the created-at string passes through unshifted.
    $scopeConfig = Mockery::mock(ScopeConfigInterface::class);
    $scopeConfig->shouldReceive('getValue')->andReturn(null);
    $objectManager->shouldReceive('create')->with(ScopeConfigInterface::class)->andReturn($scopeConfig);

    return new FulfilmentOrderBuilder($objectManager);
}

/**
 * An order the PPS builder can convert: a German destination, so age-check and customs never
 * activate, with the same address billing and shipping unless one is overridden.
 */
function createFulfilmentMagentoOrder(array $checkoutDeliveryOptions, array $overrides = []): Order
{
    $address = createAddress([
        'getCountryId' => 'DE',
        'street'       => 'Musterstrasse 1',
        'getFirstname' => 'Jan',
        'getLastname'  => 'Jansen',
    ]);

    return createOrder(array_merge([
        'getShippingAddress' => $address,
        'getBillingAddress'  => $address,
        'getStatus'          => 'processing',
        'getCreatedAt'       => '2026-08-20 10:00:00',
        'getItems'           => [createOrderItem()],
        'deliveryOptions'    => json_encode($checkoutDeliveryOptions),
    ], $overrides));
}

/**
 * A real DataObject rather than a mock: OrderLineOptionsFromOrderAdapter reads the item through
 * getData() and getProduct(), and the weight loop reads getQtyOrdered(), which magic getters give
 * for free.
 */
function createOrderItem(array $data = [], array $productData = []): DataObject
{
    $product = new DataObject(array_merge([
        'sku'    => 'SKU-1',
        'height' => 10,
        'length' => 10,
        'width'  => 10,
        'weight' => 100.0,
    ], $productData));

    return new DataObject(array_merge([
        'item_id'     => 1,
        'product_id'  => '1',
        'name'        => 'Test product',
        'description' => 'A product',
        'price'       => 10.0,
        'tax_amount'  => 2.1,
        'qty_ordered' => 1,
        'product'     => $product,
    ], $data));
}
