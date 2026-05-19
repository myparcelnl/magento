<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Proxy;

/**
 * Static configuration for the storefront API proxy: which upstream
 * hosts the proxy can forward to and which paths each API surface
 * exposes. Pure data — enforcement lives in {@see Client}.
 *
 * To expose a new upstream call, add the path under the right surface
 * in {@see self::API_SURFACES}. To expose an existing API on a new
 * environment, add an entry to {@see self::UPSTREAM_HOSTS} pointing at
 * that surface.
 */
final class ProxyConfig
{
    /** Key inside an UPSTREAM_HOSTS entry holding the upstream base URL. */
    public const KEY_URL = 'url';

    /** Key inside an UPSTREAM_HOSTS entry naming the API surface it exposes. */
    public const KEY_SURFACE = 'surface';

    /** API surface names — referenced from UPSTREAM_HOSTS and API_SURFACES. */
    public const SURFACE_CORE = 'core';
    // public const SURFACE_ORDER = 'order';

    /** Upstream-host keys — the first URL segment after /myparcel/proxy/. */
    public const HOST_CORE       = 'core';
    public const HOST_ACCEPTANCE = 'acceptance';

    /**
     * Logical API surfaces and the paths each one exposes. Deliberately
     * excludes the bare `shipments` path under core so the proxy cannot
     * be coerced into creating shipments under our API key.
     */
    public const API_SURFACES = [
        self::SURFACE_CORE => [
            'shipments/capabilities',
        ],
    ];

    /**
     * Upstream hosts, keyed by URL prefix segment. Multiple hosts may
     * share a surface (e.g. production + acceptance of the same API).
     */
    public const UPSTREAM_HOSTS = [
        self::HOST_CORE       => [self::KEY_URL => 'https://api.myparcel.nl',            self::KEY_SURFACE => self::SURFACE_CORE],
        self::HOST_ACCEPTANCE => [self::KEY_URL => 'https://acceptance.api.myparcel.nl', self::KEY_SURFACE => self::SURFACE_CORE],
    ];
}
