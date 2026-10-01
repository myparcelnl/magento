<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;

// createConfig() and createUserAgent() live in Tests/Helpers, getPrivateProperty() in ReflectionHelpers.php.

it('sends the API feature flags on every shipment request', function () {
    $api = (new ShipmentApiProvider(createConfig(), createUserAgent()))->clientFor('test-key');

    // Without it the API refuses no_tracking on a shipment and still offers the deprecated tracked.
    expect(getPrivateProperty($api, 'client')->getConfig('headers')['x-dmp-no-tracking'] ?? null)->toBe('true');
});

it('builds one client per api key', function () {
    $provider = new ShipmentApiProvider(createConfig(), createUserAgent());

    expect($provider->clientFor('test-key'))->toBe($provider->clientFor('test-key'))
        ->and($provider->clientFor('other-key'))->not->toBe($provider->clientFor('test-key'));
});
