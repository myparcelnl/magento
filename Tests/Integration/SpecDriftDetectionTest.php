<?php

declare(strict_types=1);

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
    return getRemoteOrderApiSpec()['components']['schemas'][$schemaName]['enum'] ?? [];
}

function getRemotePropertyNames(string $schemaName): array
{
    $schemas = getRemoteOrderApiSpec()['components']['schemas'] ?? [];
    $schema  = $schemas[$schemaName] ?? [];

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

function loadLocalSchema(): stdClass
{
    static $schema;

    if ($schema !== null) {
        return $schema;
    }

    $path   = __DIR__ . '/../../docs/openapi/delivery-options.schema.json';
    $schema = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);

    return $schema;
}

function localEnumValues(string $property): array
{
    $enum = loadLocalSchema()->properties->$property->enum;

    return array_filter($enum, fn ($v) => $v !== null);
}

function localPropertyNames(string $definition): array
{
    return array_keys((array) loadLocalSchema()->definitions->$definition->properties);
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
        'Remote Order API has carriers missing from local schema: ' . implode(', ', $missingLocally)
    );
    expect($extraLocally)->toBeEmpty(
        'Local schema has carriers not in remote Order API: ' . implode(', ', $extraLocally)
    );
});

it('local packageType enum matches the remote Order API spec', function () {
    $remoteValues = getRemoteEnum('PackageType');
    $localValues  = localEnumValues('packageType');

    $missingLocally = array_diff($remoteValues, $localValues);
    $extraLocally   = array_diff($localValues, $remoteValues);

    expect($missingLocally)->toBeEmpty(
        'Remote Order API has package types missing from local schema: ' . implode(', ', $missingLocally)
    );
    expect($extraLocally)->toBeEmpty(
        'Local schema has package types not in remote Order API: ' . implode(', ', $extraLocally)
    );
});

it('local deliveryType enum matches the remote Order API spec', function () {
    $remoteValues = getRemoteEnum('DeliveryType');
    $localValues  = localEnumValues('deliveryType');

    $missingLocally = array_diff($remoteValues, $localValues);
    $extraLocally   = array_diff($localValues, $remoteValues);

    expect($missingLocally)->toBeEmpty(
        'Remote Order API has delivery types missing from local schema: ' . implode(', ', $missingLocally)
    );
    expect($extraLocally)->toBeEmpty(
        'Local schema has delivery types not in remote Order API: ' . implode(', ', $extraLocally)
    );
});

it('local shipmentOptions fields all exist in the remote Order API spec', function () {
    $remoteFields = getRemotePropertyNames('ShipmentOptions');
    $localFields  = localPropertyNames('ShipmentOptions');

    // Only check that our local fields exist in the remote spec.
    // The reverse direction (remote fields missing locally) is expected — we
    // intentionally support a subset of ShipmentOptions.
    $extraLocally = array_diff($localFields, $remoteFields);

    expect($extraLocally)->toBeEmpty(
        'Local schema has ShipmentOptions fields not in remote Order API: ' . implode(', ', $extraLocally)
    );
});
