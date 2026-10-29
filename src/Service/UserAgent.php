<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Framework\App\ProductMetadataInterface;

/**
 * How this module identifies itself to MyParcel, in one place.
 *
 * `Magento2` is the platform version and `MyParcel-Magento2` the module version — two different
 * questions support asks, so they are two keys. Anything carrying the SDK's HasUserAgent trait
 * takes map(); a caller that builds its own header string takes header().
 *
 * The SDK's getUserAgentHeader() appends `MyParcelNL-SDK` and `php` itself, and the later value
 * wins, so `php` below is redundant on that path and load-bearing on the hand-built ones. It is
 * listed once so the map is the whole answer wherever it is used. `MyParcelNL-SDK` is not: reading
 * the SDK version means a private trait method, and the SDK adds it to every call it makes.
 */
class UserAgent
{
    private Config                   $config;
    private ProductMetadataInterface $productMetadata;

    public function __construct(Config $config, ProductMetadataInterface $productMetadata)
    {
        $this->config          = $config;
        $this->productMetadata = $productMetadata;
    }

    /** @return array<string,string> as HasUserAgent::setUserAgents() takes it */
    public function map(): array
    {
        return [
            'Magento2'          => (string) $this->productMetadata->getVersion(),
            'MyParcel-Magento2' => $this->config->getVersion(),
            'php'               => PHP_VERSION,
        ];
    }

    /** `Magento2/2.4.6 MyParcel-Magento2/5.9.0 php/8.1.2` — the SDK's own header format. */
    public function header(): string
    {
        $parts = [];

        foreach ($this->map() as $name => $version) {
            $parts[] = $name . '/' . $version;
        }

        return implode(' ', $parts);
    }
}
