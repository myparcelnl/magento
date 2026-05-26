<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Proxy;

/**
 * Static configuration for the storefront API proxy: which upstream
 * hosts the proxy can forward to and which paths each host exposes.
 * Pure data — enforcement lives in {@see Client}.
 *
 * Every host has a production URL and a fixed acceptance counterpart;
 * callers pick between them with the optional `/acceptance` URL
 * segment, so `/myparcel/proxy/<host>/<path>` hits production and
 * `/myparcel/proxy/<host>/acceptance/<path>` hits acceptance.
 *
 * To expose a new upstream call, add it under the right host in
 * {@see self::HOSTS}. To expose a new host, add an entry with both its
 * production and acceptance URLs.
 */
final class ProxyConfig
{
    /** Inner-key constants for HOSTS entries. */
    public const KEY_URL            = 'url';
    public const KEY_ACCEPTANCE_URL = 'acceptanceUrl';
    public const KEY_PATHS          = 'paths';

    /** URL segment that switches a host to its acceptance environment. */
    public const ACCEPTANCE_SEGMENT = 'acceptance';

    /** Host keys — the first URL segment after /myparcel/proxy/. */
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
