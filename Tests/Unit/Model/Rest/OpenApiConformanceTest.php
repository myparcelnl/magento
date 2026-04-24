<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use MyParcelNL\Magento\Model\Rest\ProblemDetails;
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
use Nyholm\Psr7\Response;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function specPath(): string
{
    return __DIR__ . '/../../../../docs/openapi/delivery-options.yaml';
}

function loadSpec(): OpenApi
{
    static $spec;

    if ($spec !== null) {
        return $spec;
    }

    $spec = Reader::readFromYamlFile(specPath());

    return $spec;
}

function specEnumValues(string $property): array
{
    return loadSpec()->components->schemas['DeliveryOptions']->properties[$property]->enum;
}

function specPropertyNames(string $schemaName): array
{
    return array_keys(loadSpec()->components->schemas[$schemaName]->properties);
}

function validateAgainstSpec(array $data, int $statusCode = 200): array
{
    static $validator;

    if ($validator === null) {
        $validator = (new ValidatorBuilder())
            ->fromYamlFile(specPath())
            ->getResponseValidator();
    }

    $contentType = $statusCode === 200 ? 'application/json' : 'application/problem+json';
    $operation   = new OperationAddress('/V1/myparcel/delivery-options', 'get');
    $response    = new Response(
        $statusCode,
        ['Content-Type' => $contentType],
        json_encode($data, JSON_THROW_ON_ERROR),
    );

    try {
        $validator->validate($operation, $response);

        return [];
    } catch (\League\OpenAPIValidation\PSR7\Exception\ValidationFailed $e) {
        $messages = [$e->getMessage()];
        $prev     = $e->getPrevious();

        while ($prev !== null) {
            $messages[] = $prev->getMessage();
            $prev       = $prev->getPrevious();
        }

        return $messages;
    }
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

// ---------------------------------------------------------------------------
// Schema validation — full pipeline
// ---------------------------------------------------------------------------

it('full response validates against the DeliveryOptions schema', function () {
    $handler  = buildRequestHandler();
    $response = $handler->transform(mockFullAdapter());

    $errors = validateAgainstSpec($response);

    expect($errors)->toBeEmpty(implode("\n", $errors));
});

it('minimal (all-null) response validates against the DeliveryOptions schema', function () {
    $handler  = buildRequestHandler();
    $response = $handler->transform(mockMinimalAdapter());

    $errors = validateAgainstSpec($response);

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

    $response = buildRequestHandler()->transform($adapter);
    $errors   = validateAgainstSpec($response);

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

    $response = buildRequestHandler()->transform($adapter);
    $errors   = validateAgainstSpec($response);

    expect($errors)->toBeEmpty(implode("\n", $errors));
});

it('response with all boolean shipment options enabled validates', function () {
    $response = buildRequestHandler()->transform(mockFullAdapter([
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
    ]));

    $errors = validateAgainstSpec($response);

    expect($errors)->toBeEmpty(implode("\n", $errors));
});

it('error response validates against the ProblemDetails schema', function (int $statusCode) {
    $problem = new ProblemDetails(null, $statusCode, ProblemDetails::titleForStatus($statusCode), 'Test detail');
    $errors  = validateAgainstSpec($problem->jsonSerialize(), $statusCode);

    expect($errors)->toBeEmpty(implode("\n", $errors));
})->with([400, 404, 406, 409, 500]);

// ---------------------------------------------------------------------------
// Bidirectional enum conformance — local spec vs SDK
// ---------------------------------------------------------------------------

it('local spec carrier enum matches SDK Carrier enum', function () {
    $sdkValues  = OrderApiCarrier::getAllowableEnumValues();
    $specValues = specEnumValues('carrier');

    $missingFromSpec = array_diff($sdkValues, $specValues);
    $extraInSpec     = array_diff($specValues, $sdkValues);

    expect($missingFromSpec)->toBeEmpty(
        'SDK carriers missing from local spec: ' . implode(', ', $missingFromSpec)
    )
                            ->and($extraInSpec)->toBeEmpty(
            'Local spec has carriers not in SDK: ' . implode(', ', $extraInSpec)
        )
    ;
});

it('local spec packageType enum matches SDK PackageType enum', function () {
    $sdkValues  = OrderApiPackageType::getAllowableEnumValues();
    $specValues = specEnumValues('packageType');

    $missingFromSpec = array_diff($sdkValues, $specValues);
    $extraInSpec     = array_diff($specValues, $sdkValues);

    expect($missingFromSpec)->toBeEmpty(
        'SDK package types missing from local spec: ' . implode(', ', $missingFromSpec)
    )
                            ->and($extraInSpec)->toBeEmpty(
            'Local spec has package types not in SDK: ' . implode(', ', $extraInSpec)
        )
    ;
});

it('local spec deliveryType enum matches SDK DeliveryType enum', function () {
    $sdkValues  = OrderApiDeliveryType::getAllowableEnumValues();
    $specValues = specEnumValues('deliveryType');

    $missingFromSpec = array_diff($sdkValues, $specValues);
    $extraInSpec     = array_diff($specValues, $sdkValues);

    expect($missingFromSpec)->toBeEmpty(
        'SDK delivery types missing from local spec: ' . implode(', ', $missingFromSpec)
    )
                            ->and($extraInSpec)->toBeEmpty(
            'Local spec has delivery types not in SDK: ' . implode(', ', $extraInSpec)
        )
    ;
});

// ---------------------------------------------------------------------------
// Negative cases — verify the spec actually rejects invalid output
// ---------------------------------------------------------------------------

it('rejects an unknown carrier enum value', function () {
    $handler  = buildRequestHandler();
    $response = $handler->transform(mockFullAdapter());

    $response['carrier'] = 'INVALID_CARRIER';

    $errors = validateAgainstSpec($response);

    expect($errors)->not->toBeEmpty('Schema should reject an unknown carrier enum value');
});

it('rejects a ProblemDetails response missing a required field', function () {
    $problem = new ProblemDetails(null, 400, ProblemDetails::titleForStatus(400), 'Test detail');
    $data    = $problem->jsonSerialize();

    unset($data['status']);

    $errors = validateAgainstSpec($data, 400);

    expect($errors)->not->toBeEmpty('Schema should reject a ProblemDetails response missing the required "status" field');
});

it('rejects a boolean shipment option that contains properties', function () {
    $handler  = buildRequestHandler();
    $response = $handler->transform(mockFullAdapter(['hasSignature' => true]));

    // The transformer returns stdClass for shipmentOptions (empty objects for booleans).
    // Round-trip through JSON to get a plain array we can manipulate.
    $response = json_decode(json_encode($response), true);

    $response['shipmentOptions']['requiresSignature'] = ['unexpected' => true];

    $errors = validateAgainstSpec($response);

    expect($errors)->not->toBeEmpty('Schema should reject a boolean option with properties (maxProperties: 0)');
});

// ---------------------------------------------------------------------------
// ShipmentOptions field conformance — local spec vs transformer
// ---------------------------------------------------------------------------

it('local spec shipmentOptions fields match the transformer output fields', function () {
    $specFields = specPropertyNames('ShipmentOptions');

    // Intentionally duplicated from ShipmentOptionsTransformer::BOOLEAN_GETTER_TO_ORDER_API_KEY.
    // This creates a three-way cross-check (test ↔ transformer ↔ spec) rather than a two-way
    // sync. Reading from the constant would make this a tautology — keep the list explicit.
    $attributeMap  = OrderApiShipmentOptions::attributeMap();
    $booleanFields = [
        'requires_age_verification', 'requires_signature', 'recipient_only_delivery',
        'oversized_package', 'print_return_label_at_drop_off', 'hide_sender',
        'priority_delivery', 'requires_receipt_code', 'same_day_delivery', 'scheduled_collection',
    ];

    $transformerFields = array_map(fn ($key) => $attributeMap[$key], $booleanFields);
    $transformerFields[] = $attributeMap['insurance'];
    $transformerFields[] = $attributeMap['custom_label_text'];

    $missingFromSpec = array_diff($transformerFields, $specFields);
    $extraInSpec     = array_diff($specFields, $transformerFields);

    expect($missingFromSpec)->toBeEmpty(
        'Transformer produces fields missing from local spec: ' . implode(', ', $missingFromSpec)
    )
                            ->and($extraInSpec)->toBeEmpty(
            'Local spec has ShipmentOptions fields the transformer cannot produce: ' . implode(', ', $extraInSpec)
        )
    ;
});
