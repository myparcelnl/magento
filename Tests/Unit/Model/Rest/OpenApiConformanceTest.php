<?php

declare(strict_types=1);

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
use Nyholm\Psr7\Response;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function specPath(): string
{
    return __DIR__ . '/../../../../docs/openapi/delivery-options.yaml';
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
    $response = $handler->transform(fullDeliveryOptions());

    $errors = validateAgainstSpec($response);

    expect($errors)->toBeEmpty(implode("\n", $errors));
});

it('response with all boolean shipment options enabled validates', function () {
    $response = buildRequestHandler()->transform(fullDeliveryOptions([
        'hasAgeCheck'         => true,
        'hasSignature'        => true,
        'hasOnlyRecipient'    => true,
        'hasLargeFormat'      => true,
        'hasReturn'           => true,
        'hasHideSender'       => true,
        'hasPriorityDelivery' => true,
        'hasReceiptCode'      => true,
        'hasSameDayDelivery'  => true,
        'hasCollect'          => true,
        'getInsurance'        => 100,
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
// Negative cases — verify the spec actually rejects invalid output
// ---------------------------------------------------------------------------

it('rejects an unknown carrier enum value', function () {
    $handler  = buildRequestHandler();
    $response = $handler->transform(fullDeliveryOptions());

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
