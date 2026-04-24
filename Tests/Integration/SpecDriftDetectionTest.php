<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function getRemoteOrderApiSpec(): array
{
    static $spec;

    if ($spec !== null) {
        return $spec;
    }

    $json = @file_get_contents('https://order.api.myparcel.nl/openapi.json');

    if ($json === false) {
        test()->markTestSkipped('Could not fetch Order API spec from order.api.myparcel.nl');
    }

    $spec = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return $spec;
}

function getRemoteEnum(string $schemaName): array
{
    $schemas = getRemoteOrderApiSpec()['components']['schemas'] ?? [];

    if (! isset($schemas[$schemaName])) {
        test()->fail("Remote schema '{$schemaName}' not found at components.schemas — the remote spec may have restructured.");
    }

    if (! isset($schemas[$schemaName]['enum'])) {
        test()->fail("Remote schema '{$schemaName}' exists but has no 'enum' key — it may have been wrapped in allOf or otherwise restructured.");
    }

    return $schemas[$schemaName]['enum'];
}

function getRemotePropertyNames(string $schemaName): array
{
    $schemas = getRemoteOrderApiSpec()['components']['schemas'] ?? [];

    if (! isset($schemas[$schemaName])) {
        test()->fail("Remote schema '{$schemaName}' not found at components.schemas — the remote spec may have restructured.");
    }

    $schema = $schemas[$schemaName];

    if (isset($schema['properties'])) {
        return array_keys($schema['properties']);
    }

    if (isset($schema['allOf'])) {
        $properties = [];
        foreach ($schema['allOf'] as $part) {
            if (isset($part['$ref'])) {
                $refName    = basename($part['$ref']);
                $properties = array_merge($properties, array_keys($schemas[$refName]['properties'] ?? []));
            } elseif (isset($part['properties'])) {
                $properties = array_merge($properties, array_keys($part['properties']));
            }
        }
        return $properties;
    }

    return [];
}

function loadLocalSpec(): OpenApi
{
    static $spec;

    if ($spec !== null) {
        return $spec;
    }

    $spec = Reader::readFromYamlFile(__DIR__ . '/../../docs/openapi/delivery-options.yaml');

    return $spec;
}

function localEnumValues(string $property): array
{
    return loadLocalSpec()->components->schemas['DeliveryOptions']->properties[$property]->enum;
}

function localPropertyNames(string $definition): array
{
    return array_keys(loadLocalSpec()->components->schemas[$definition]->properties);
}

// ---------------------------------------------------------------------------
// Drift detection — local spec vs remote Order API spec
// ---------------------------------------------------------------------------

it('local carrier enum matches the remote Order API spec', function () {
    $remoteValues = getRemoteEnum('Carrier');
    $localValues  = localEnumValues('carrier');

    $missingLocally  = array_diff($remoteValues, $localValues);
    $extraLocally    = array_diff($localValues, $remoteValues);

    expect($missingLocally)->toBeEmpty(
        'Remote Order API has carriers missing from local spec: ' . implode(', ', $missingLocally)
    )
                           ->and($extraLocally)->toBeEmpty(
            'Local spec has carriers not in remote Order API: ' . implode(', ', $extraLocally)
        )
    ;
});

it('local packageType enum matches the remote Order API spec', function () {
    $remoteValues = getRemoteEnum('PackageType');
    $localValues  = localEnumValues('packageType');

    $missingLocally = array_diff($remoteValues, $localValues);
    $extraLocally   = array_diff($localValues, $remoteValues);

    expect($missingLocally)->toBeEmpty(
        'Remote Order API has package types missing from local spec: ' . implode(', ', $missingLocally)
    )
                           ->and($extraLocally)->toBeEmpty(
            'Local spec has package types not in remote Order API: ' . implode(', ', $extraLocally)
        )
    ;
});

it('local deliveryType enum matches the remote Order API spec', function () {
    $remoteValues = getRemoteEnum('DeliveryType');
    $localValues  = localEnumValues('deliveryType');

    $missingLocally = array_diff($remoteValues, $localValues);
    $extraLocally   = array_diff($localValues, $remoteValues);

    expect($missingLocally)->toBeEmpty(
        'Remote Order API has delivery types missing from local spec: ' . implode(', ', $missingLocally)
    )
                           ->and($extraLocally)->toBeEmpty(
            'Local spec has delivery types not in remote Order API: ' . implode(', ', $extraLocally)
        )
    ;
});

it('local shipmentOptions fields all exist in the remote Order API spec', function () {
    $remoteFields = getRemotePropertyNames('ShipmentOptions');
    $localFields  = localPropertyNames('ShipmentOptions');

    // Only check that our local fields exist in the remote spec.
    // The reverse direction (remote fields missing locally) is expected — we
    // intentionally support a subset of ShipmentOptions.
    $extraLocally = array_diff($localFields, $remoteFields);

    expect($extraLocally)->toBeEmpty(
        'Local spec has ShipmentOptions fields not in remote Order API: ' . implode(', ', $extraLocally)
    );
});
