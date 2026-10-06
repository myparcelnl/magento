<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

/**
 * The fallback track & trace URL, for a shipment stored before the module read the link from the API.
 *
 * The host follows the account's proposition: a Belgian account's consumer portal is not myparcel.me.
 * Proposition ids are the API's own (AccountDefsPlatformId); an unmapped or unknown one keeps the Dutch
 * host, which is what every install produced before this map existed. Both values live in etc/di.xml.
 */
class TrackTraceUrl
{
    private string $defaultBaseUrl;

    /** @var array<int, string> */
    private array $baseUrlsByProposition;

    /**
     * @param array<int|string, string> $baseUrlsByProposition proposition id => consumer portal base url
     */
    public function __construct(
        string $defaultBaseUrl,
        array  $baseUrlsByProposition = []
    )
    {
        $this->defaultBaseUrl        = $defaultBaseUrl;
        $this->baseUrlsByProposition = $baseUrlsByProposition;
    }

    public function create(
        string  $barcode,
        string  $postalCode,
        ?string $countryCode = null,
        ?int    $propositionId = null
    ): string
    {
        // Every part is a path segment, so it is encoded: a barcode or postcode carrying a slash or
        // a quote would otherwise change the URL the admin clicks. Spaces go before the encoding,
        // or they survive as %20.
        $url = $this->baseUrlFor($propositionId)
            . rawurlencode($barcode)
            . '/'
            . rawurlencode(str_replace(' ', '', $postalCode));

        if ($countryCode) {
            $url .= '/' . rawurlencode($countryCode);
        }

        return $url;
    }

    private function baseUrlFor(?int $propositionId): string
    {
        if (null === $propositionId) {
            return $this->defaultBaseUrl;
        }

        return $this->baseUrlsByProposition[$propositionId] ?? $this->defaultBaseUrl;
    }
}
