<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Capabilities\InsuranceRange;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;

/**
 * Reads an account's contract definitions out of the stored account settings row.
 *
 * The answer is per carrier and has no country. Never read it as a per-destination answer:
 * for a concrete shipment, ask Capabilities\CapabilitiesProvider. Makes no API call. A missing or
 * unparseable row answers permissive, so a merchant can still configure insurance.
 */
class ContractDefinitions
{
    private ScopeConfigInterface $scopeConfig;
    private Fingerprint          $fingerprint;
    private Config               $config;

    /** @var array<string, CapabilitySet> keyed by fingerprint, so a settings page decodes once */
    private array $memo = [];

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Fingerprint          $fingerprint,
        Config               $config
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->fingerprint = $fingerprint;
        $this->config      = $config;
    }

    /** Get the contract definitions of the account with this API key. An empty key answers permissive. */
    public function forApiKey(string $apiKey): CapabilitySet
    {
        if ('' === $apiKey) {
            return CapabilitySet::permissive();
        }

        $fingerprint = $this->fingerprint->of($apiKey);

        if (! isset($this->memo[$fingerprint])) {
            $this->memo[$fingerprint] = $this->read($fingerprint);
        }

        return $this->memo[$fingerprint];
    }

    /** Get the capabilities of the account at one admin scope ('default', 'websites' or 'stores'). */
    public function forScope(string $scopeName, ?int $scopeId): CapabilitySet
    {
        $apiKey = (string) $this->config->getScopedConfig(Config::XML_PATH_API_KEY, $scopeName, $scopeId);

        return $this->forApiKey(trim($apiKey));
    }

    /**
     * Get the insurance range of one carrier from the contract, or null when none resolves.
     * The range has no zone, so the export checks the amount again for each destination.
     * It is the range of a package; a carrier without a package contract gets its first range.
     */
    public function insuranceRangeFor(string $carrier, string $scopeName, ?int $scopeId): ?InsuranceRange
    {
        $capabilities = $this->forScope($scopeName, $scopeId);

        return InsuranceRange::fromOptionValue(
            $capabilities->optionValue($carrier, PackageType::PACKAGE_NAME, ShipmentOption::INSURANCE)
            ?? $capabilities->optionValue($carrier, null, ShipmentOption::INSURANCE)
        );
    }

    /**
     * Get the contract definition items stored for this API key, or null when none are stored.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function storedItems(string $apiKey): ?array
    {
        return $this->itemsAt($this->fingerprint->of($apiKey));
    }

    private function read(string $fingerprint): CapabilitySet
    {
        $items = $this->itemsAt($fingerprint);

        return null === $items ? CapabilitySet::permissive() : CapabilitySet::fromContractDefinitionItems($items);
    }

    /** @return array<int, array<string, mixed>>|null */
    private function itemsAt(string $fingerprint): ?array
    {
        // Always default scope, whatever scope the api key itself is configured at.
        $stored  = $this->scopeConfig->getValue(Config::XML_PATH_ACCOUNT_SETTINGS . $fingerprint);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        $items   = is_array($decoded) ? ($decoded['contract_definitions'] ?? null) : null;

        // An empty list is "we could not find out", not "this account has no carriers". An account
        // with no contract at all cannot ship, so permissive is both safer and no less true.
        return is_array($items) && [] !== $items ? $items : null;
    }
}
