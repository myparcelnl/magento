<?php

declare(strict_types=1);

/**
 * Two versions, two keys, and they are easy to swap: `Magento2` is the platform and
 * `MyParcel-Magento2` the module. A refactor once dropped the platform key entirely and no test
 * noticed, so each case names which source it expects on which key.
 */
it('reports the Magento platform version and the module version under their own keys', function () {
    $map = createUserAgent('2.4.7-p3', '5.10.0')->map();

    expect($map['Magento2'])->toBe('2.4.7-p3')
        ->and($map['MyParcel-Magento2'])->toBe('5.10.0');
});

it('carries the PHP version, which a hand-built header would otherwise lose', function () {
    // The SDK appends this itself, but Capabilities\Client and the generated client's transport
    // header build their own, so it has to be in the map rather than added at one call site.
    expect(createUserAgent()->map()['php'])->toBe(PHP_VERSION);
});

it('formats the header as name/version pairs, space separated', function () {
    $header = createUserAgent('2.4.6', '5.9.0')->header();

    expect($header)->toBe(sprintf('Magento2/2.4.6 MyParcel-Magento2/5.9.0 php/%s', PHP_VERSION));
});
