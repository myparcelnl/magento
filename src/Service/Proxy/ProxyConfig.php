<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Proxy;

/**
 * Lists the upstream hosts the storefront API proxy forwards to, and the paths each host exposes.
 * Pure data: {@see Client} enforces it.
 *
 * `/myparcel/proxy/<host>/<path>` hits production, `/myparcel/proxy/<host>/acceptance/<path>` hits
 * acceptance. A new host needs both its production and its acceptance URL.
 */
final class ProxyConfig
{
    /** Inner-key constants for HOSTS entries. */
    public const KEY_URL            = 'url';
    public const KEY_ACCEPTANCE_URL = 'acceptanceUrl';
    public const KEY_PATHS          = 'paths';

    /** URL segment that switches a host to its acceptance environment. */
    public const ACCEPTANCE_SEGMENT = 'acceptance';

    /** Host keys: the first URL segment after /myparcel/proxy/. */
    public const HOST_CORE    = 'core';
    public const HOST_ADDRESS = 'address';

    /**
     * Upstream hosts and the paths each one exposes. Deliberately omits
     * the bare `shipments` path under `core` so the proxy cannot be
     * coerced into creating shipments under our API key.
     */
    public const HOSTS = [
        self::HOST_CORE => [
            self::KEY_URL            => 'https://api.myparcel.nl',
            self::KEY_ACCEPTANCE_URL => 'https://api.acceptance.myparcel.nl',
            self::KEY_PATHS          => [
                'shipments/capabilities',
            ],
        ],
        self::HOST_ADDRESS => [
            self::KEY_URL            => 'https://address.api.myparcel.nl',
            self::KEY_ACCEPTANCE_URL => 'https://address.api.acceptance.myparcel.nl',
            self::KEY_PATHS          => [
                // populate when the widget exercises this host
            ],
        ],
    ];
}
