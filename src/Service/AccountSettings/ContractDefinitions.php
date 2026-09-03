<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Capabilities\InsuranceRange;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;

/**
 * Reads an account's contract definitions out of the stored account settings row.
 *
 * Contract definitions answer what an account's contract allows at all, with no shipment in hand.
 * They carry no country, so what comes back is a per-carrier answer and must never be read as a
 * per-destination one — a concrete shipment asks Capabilities\Repository instead (DR-19).
 *
 * The row is fetched at import time, not here, so this makes no API call and can be asked once per
 * field on a settings page. Missing or unparseable answers permissive, per FR-000010: a merchant
 * must still be able to configure insurance when we cannot confirm the bounds.
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

    /**
     * The account configured at one admin scope. Scope names are Magento's own — 'default',
     * 'websites', 'stores' — as the dynamic settings form posts them.
     */
    public function forScope(string $scopeName, ?int $scopeId): CapabilitySet
    {
        $apiKey = (string) $this->config->getScopedConfig(Config::XML_PATH_API_KEY, $scopeName, $scopeId);

        return $this->forApiKey(trim($apiKey));
    }

    /**
     * The advisory insurance bound this account's contract sets for one carrier, or null when none
     * could be resolved. It has no zone: contract definitions carry no country (DR-19).
     *
     * The settings form and the save observer both ask through here, so what a field states and what
     * a save enforces cannot drift apart.
     */
    public function insuranceRangeFor(string $carrier, string $scopeName, ?int $scopeId): ?InsuranceRange
    {
        return InsuranceRange::fromOptionValue(
            $this->forScope($scopeName, $scopeId)->optionValue($carrier, null, ShipmentOption::INSURANCE)
        );
    }

    private function read(string $fingerprint): CapabilitySet
    {
        // Always default scope, whatever scope the api key itself is configured at.
        $stored = $this->scopeConfig->getValue(Config::XML_PATH_ACCOUNT_SETTINGS . $fingerprint);

        if (! is_string($stored) || '' === $stored) {
            return CapabilitySet::permissive();
        }

        $decoded = json_decode($stored, true);

        $items = $decoded['contract_definitions'] ?? null;

        // An empty list is "we could not find out", not "this account has no carriers". An account
        // with no contract at all cannot ship, so permissive is both safer and no less true.
        if (! is_array($decoded) || ! is_array($items) || [] === $items) {
            return CapabilitySet::permissive();
        }

        return CapabilitySet::fromContractDefinitionItems($items);
    }
}
