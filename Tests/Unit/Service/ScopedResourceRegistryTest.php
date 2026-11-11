<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\ScopedResourceRegistry;

it('reports nothing covered when configured empty', function () {
    $registry = new ScopedResourceRegistry();

    expect($registry->isCovered('Magento_Sales::actions_view'))->toBeFalse();
    expect($registry->all())->toBe([]);
});

it('covers exactly the configured resource ids', function () {
    $registry = new ScopedResourceRegistry([
        'Magento_Sales::actions_view',
        'MyParcelNL_Magento::delivery_options_read',
    ]);

    expect($registry->isCovered('Magento_Sales::actions_view'))->toBeTrue();
    expect($registry->isCovered('MyParcelNL_Magento::delivery_options_read'))->toBeTrue();
    expect($registry->isCovered('Magento_Customer::manage'))->toBeFalse();
    expect($registry->isCovered('MyParcelNL_Magento::myparcelnl_magento_settings'))->toBeFalse();
});

it('treats resource ids as case-sensitive', function () {
    $registry = new ScopedResourceRegistry(['Magento_Sales::actions_view']);

    expect($registry->isCovered('magento_sales::actions_view'))->toBeFalse();
});

/**
 * Coverage regression: every <resource name="…"/> declared in etc/integration.xml
 * must be present in the registry configured in etc/webapi_rest/di.xml.
 *
 * Out-of-sync grants and registry are exactly the mistake the deny-by-default rule
 * is meant to prevent — TR-000004 §Definition of Done calls this guarantee out
 * explicitly. Failing this test means an integration grant exists with no enforcement
 * counterpart, which is a security regression.
 */
it('integration.xml grants are a subset of the registry configured in webapi_rest/di.xml', function () {
    $moduleRoot = dirname(__DIR__, 3);

    $integrationXml = simplexml_load_file($moduleRoot . '/etc/integration.xml');
    expect($integrationXml)->not->toBeFalse();
    $grants = [];
    foreach ($integrationXml->integration->resources->resource as $resource) {
        $grants[] = (string) $resource['name'];
    }

    $diXml = simplexml_load_file($moduleRoot . '/etc/webapi_rest/di.xml');
    expect($diXml)->not->toBeFalse();
    $registryItems = [];
    foreach ($diXml->type as $type) {
        if ((string) $type['name'] !== ScopedResourceRegistry::class) {
            continue;
        }
        foreach ($type->arguments->argument as $argument) {
            if ((string) $argument['name'] !== 'resources') {
                continue;
            }
            foreach ($argument->item as $item) {
                $registryItems[] = (string) $item;
            }
        }
    }

    expect($grants)->not->toBe([]);
    expect($registryItems)->not->toBe([]);

    $missing = array_diff($grants, $registryItems);
    expect($missing)->toBe([], 'integration.xml grants without a ScopedResourceRegistry entry: ' . implode(', ', $missing));
});
