<?php

declare(strict_types=1);

use JsonSchema\Validator;
use MyParcelNL\Magento\Model\Rest\Request\OrderDeliveryOptionsV1Request;
use MyParcelNL\Magento\Model\Rest\Transformer\CarrierTransformer;
use MyParcelNL\Magento\Model\Rest\Transformer\DateTransformer;
use MyParcelNL\Magento\Model\Rest\Transformer\DeliveryTypeTransformer;
use MyParcelNL\Magento\Model\Rest\Transformer\PackageTypeTransformer;
use MyParcelNL\Magento\Model\Rest\Transformer\PickupLocationTransformer;
use MyParcelNL\Magento\Model\Rest\Transformer\ShipmentOptionsTransformer;
use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\Carrier as OrderApiCarrier;
use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\DeliveryType as OrderApiDeliveryType;
use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\PackageType as OrderApiPackageType;
use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\ShipmentOptions as OrderApiShipmentOptions;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function loadSchema(): stdClass
{
    static $schema;

    if ($schema !== null) {
        return $schema;
    }

    $path = __DIR__ . '/../../../../docs/openapi/delivery-options.schema.json';
    $schema = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);

    return $schema;
}

function schemaEnumValues(string $property): array
{
    $enum = loadSchema()->properties->$property->enum;

    return array_filter($enum, fn ($v) => $v !== null);
}

function schemaPropertyNames(string $definition): array
{
    return array_keys((array) loadSchema()->definitions->$definition->properties);
}

function validateAgainstSchema($data): array
{
    $validator = new Validator();
    $validator->validate($data, loadSchema());

    if ($validator->isValid()) {
        return [];
    }

    return array_map(
        fn ($error) => sprintf('[%s] %s', $error['property'], $error['message']),
        $validator->getErrors()
    );
}

function buildRequestHandler(): OrderDeliveryOptionsV1Request
{
    return new OrderDeliveryOptionsV1Request(
        new CarrierTransformer(),
        new PackageTypeTransformer(),
        new DeliveryTypeTransformer(),
        new ShipmentOptionsTransformer(),
        new DateTransformer(),
        new PickupLocationTransformer(),
    );
}

function transformToObject(array $response): stdClass
{
    return json_decode(json_encode($response), false, 512, JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------
// Schema validation — full pipeline
// ---------------------------------------------------------------------------

it('full response validates against the DeliveryOptions schema', function () {
    $handler  = buildRequestHandler();
    $response = transformToObject($handler->transform(mockFullAdapter()));

    $errors = validateAgainstSchema($response);

    expect($errors)->toBeEmpty(implode("\n", $errors));
});

it('minimal (all-null) response validates against the DeliveryOptions schema', function () {
    $handler  = buildRequestHandler();
    $response = transformToObject($handler->transform(mockMinimalAdapter()));

    $errors = validateAgainstSchema($response);

    expect($errors)->toBeEmpty(implode("\n", $errors));
});

it('response with only shipmentOptions validates against the schema', function () {
    $adapter = Mockery::mock(\MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter::class);
    $adapter->shouldReceive('getCarrier')->andReturn(null);
    $adapter->shouldReceive('getPackageType')->andReturn(null);
    $adapter->shouldReceive('getDeliveryType')->andReturn(null);
    $adapter->shouldReceive('getDate')->andReturn(null);
    $adapter->shouldReceive('getShipmentOptions')->andReturn(mockShipmentOptions([
        'hasSignature'     => true,
        'hasOnlyRecipient' => true,
        'getInsurance'     => 100,
    ]));
    $adapter->shouldReceive('getPickupLocation')->andReturn(null);

    $response = transformToObject(buildRequestHandler()->transform($adapter));
    $errors   = validateAgainstSchema($response);

    expect($errors)->toBeEmpty(implode("\n", $errors));
});

it('response with only pickupLocation validates against the schema', function () {
    $adapter = Mockery::mock(\MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter::class);
    $adapter->shouldReceive('getCarrier')->andReturn(null);
    $adapter->shouldReceive('getPackageType')->andReturn(null);
    $adapter->shouldReceive('getDeliveryType')->andReturn(null);
    $adapter->shouldReceive('getDate')->andReturn(null);
    $adapter->shouldReceive('getShipmentOptions')->andReturn(null);
    $adapter->shouldReceive('getPickupLocation')->andReturn(mockPickupLocation());

    $response = transformToObject(buildRequestHandler()->transform($adapter));
    $errors   = validateAgainstSchema($response);

    expect($errors)->toBeEmpty(implode("\n", $errors));
});

it('response with all boolean shipment options enabled validates', function () {
    $response = transformToObject(buildRequestHandler()->transform(mockFullAdapter([
        'hasAgeCheck'        => true,
        'hasSignature'       => true,
        'hasOnlyRecipient'   => true,
        'hasLargeFormat'     => true,
        'isReturn'           => true,
        'hasHideSender'      => true,
        'isPriorityDelivery' => true,
        'hasReceiptCode'     => true,
        'isSameDayDelivery'  => true,
        'hasCollect'         => true,
        'getInsurance'       => 100,
        'getLabelDescription' => 'Test label',
    ])));

    $errors = validateAgainstSchema($response);

    expect($errors)->toBeEmpty(implode("\n", $errors));
});

// ---------------------------------------------------------------------------
// Bidirectional enum conformance — local schema vs SDK
// ---------------------------------------------------------------------------

it('local schema carrier enum matches SDK Carrier enum', function () {
    $sdkValues    = OrderApiCarrier::getAllowableEnumValues();
    $schemaValues = schemaEnumValues('carrier');

    $missingFromSchema = array_diff($sdkValues, $schemaValues);
    $extraInSchema     = array_diff($schemaValues, $sdkValues);

    expect($missingFromSchema)->toBeEmpty(
        'SDK carriers missing from local schema: ' . implode(', ', $missingFromSchema)
    );
    expect($extraInSchema)->toBeEmpty(
        'Local schema has carriers not in SDK: ' . implode(', ', $extraInSchema)
    );
});

it('local schema packageType enum matches SDK PackageType enum', function () {
    $sdkValues    = OrderApiPackageType::getAllowableEnumValues();
    $schemaValues = schemaEnumValues('packageType');

    $missingFromSchema = array_diff($sdkValues, $schemaValues);
    $extraInSchema     = array_diff($schemaValues, $sdkValues);

    expect($missingFromSchema)->toBeEmpty(
        'SDK package types missing from local schema: ' . implode(', ', $missingFromSchema)
    );
    expect($extraInSchema)->toBeEmpty(
        'Local schema has package types not in SDK: ' . implode(', ', $extraInSchema)
    );
});

it('local schema deliveryType enum matches SDK DeliveryType enum', function () {
    $sdkValues    = OrderApiDeliveryType::getAllowableEnumValues();
    $schemaValues = schemaEnumValues('deliveryType');

    $missingFromSchema = array_diff($sdkValues, $schemaValues);
    $extraInSchema     = array_diff($schemaValues, $sdkValues);

    expect($missingFromSchema)->toBeEmpty(
        'SDK delivery types missing from local schema: ' . implode(', ', $missingFromSchema)
    );
    expect($extraInSchema)->toBeEmpty(
        'Local schema has delivery types not in SDK: ' . implode(', ', $extraInSchema)
    );
});

// ---------------------------------------------------------------------------
// ShipmentOptions field conformance — local schema vs transformer
// ---------------------------------------------------------------------------

it('local schema shipmentOptions fields match the transformer output fields', function () {
    $schemaFields = schemaPropertyNames('ShipmentOptions');

    // The transformer's possible output fields: resolved via OrderApiShipmentOptions::attributeMap()
    $attributeMap  = OrderApiShipmentOptions::attributeMap();
    $booleanFields = [
        'requires_age_verification', 'requires_signature', 'recipient_only_delivery',
        'oversized_package', 'print_return_label_at_drop_off', 'hide_sender',
        'priority_delivery', 'requires_receipt_code', 'same_day_delivery', 'scheduled_collection',
    ];

    $transformerFields = array_map(fn ($key) => $attributeMap[$key], $booleanFields);
    $transformerFields[] = $attributeMap['insurance'];
    $transformerFields[] = $attributeMap['custom_label_text'];

    $missingFromSchema      = array_diff($transformerFields, $schemaFields);
    $extraInSchema          = array_diff($schemaFields, $transformerFields);

    expect($missingFromSchema)->toBeEmpty(
        'Transformer produces fields missing from local schema: ' . implode(', ', $missingFromSchema)
    );
    expect($extraInSchema)->toBeEmpty(
        'Local schema has ShipmentOptions fields the transformer cannot produce: ' . implode(', ', $extraInSchema)
    );
});
