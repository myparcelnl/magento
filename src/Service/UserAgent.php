<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Framework\App\ProductMetadataInterface;

/**
 * Holds how this module identifies itself to MyParcel: map() for the SDK's HasUserAgent trait,
 * header() for a hand-built header.
 *
 * `php` is redundant for the SDK, which appends it itself, but a hand-built header needs it.
 * `MyParcelNL-SDK` is left out: the SDK adds it to every call it makes.
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

    /** Get the header in the SDK's format: `Magento2/2.4.6 MyParcel-Magento2/5.9.0 php/8.1.2`. */
    public function header(): string
    {
        $parts = [];

        foreach ($this->map() as $name => $version) {
            $parts[] = $name . '/' . $version;
        }

        return implode(' ', $parts);
    }
}
