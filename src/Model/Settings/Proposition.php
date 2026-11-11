<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings;

use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\AccountDefsPlatformId;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\AccountDefsPlatformName;

/**
 * The MyParcel proposition an account belongs to, and the one list of every proposition the module knows.
 *
 * Add a proposition to PROPOSITIONS only: everything that differs per proposition reads it from here.
 * An unknown id has no proposition. A caller that needs one anyway falls back to default() itself.
 */
final class Proposition
{

    /**
     * Keyed by the API's proposition id. The name is the API's own, which the delivery options widget
     * also takes. A null track & trace host means the API always returns the link on the shipment.
     *
     * @var array<int, array{name: string, countryCode: string, trackTraceUrl: string|null}>
     */
    private const PROPOSITIONS = [
        AccountDefsPlatformId::MYPARCEL => [
            'name'          => AccountDefsPlatformName::MYPARCEL,
            'countryCode'   => CountryCode::CC_NL,
            'trackTraceUrl' => 'https://myparcel.me/track-trace/',
        ],
        AccountDefsPlatformId::BELGIE   => [
            'name'          => AccountDefsPlatformName::BELGIE,
            'countryCode'   => CountryCode::CC_BE,
            'trackTraceUrl' => 'https://sendmyparcel.me/track-trace/',
        ],
        AccountDefsPlatformId::ITALY    => [
            'name'          => AccountDefsPlatformName::ITALY,
            'countryCode'   => CountryCode::CC_IT,
            'trackTraceUrl' => null,
        ],
    ];

    private int     $id;
    private string  $name;
    private string  $countryCode;
    private ?string $trackTraceUrl;

    private function __construct(int $id, string $name, string $countryCode, ?string $trackTraceUrl)
    {
        $this->id            = $id;
        $this->name          = $name;
        $this->countryCode   = $countryCode;
        $this->trackTraceUrl = $trackTraceUrl;
    }

    /** Null for no id, or for an id this module does not list. */
    public static function forId(?int $id): ?self
    {
        return null !== $id && isset(self::PROPOSITIONS[$id]) ? self::listed($id) : null;
    }

    /** MyParcel, which every install was before propositions were read. */
    public static function default(): self
    {
        return self::listed(AccountDefsPlatformId::MYPARCEL);
    }

    private static function listed(int $id): self
    {
        $entry = self::PROPOSITIONS[$id];

        return new self($id, $entry['name'], $entry['countryCode'], $entry['trackTraceUrl']);
    }

    public static function ids(): array
    {
        return array_keys(self::PROPOSITIONS);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** The account's home country. */
    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    /** The consumer portal's base url, with a trailing slash. */
    public function getTrackTraceUrl(): ?string
    {
        return $this->trackTraceUrl;
    }
}
