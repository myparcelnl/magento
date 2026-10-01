<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Blueprint;

use MyParcelNL\Magento\Service\AccountSettings\ContractDefinitions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\MailboxInternational;

/**
 * The settings form for one admin scope, built from the account whose api key that scope holds.
 *
 * Reads the stored account settings row, so rendering the form makes no API call. Memoised per
 * scope, because the form asks once per field.
 */
class ScopeBlueprints
{
    private ContractDefinitions $contractDefinitions;

    private Config $config;

    private MailboxInternational $mailboxInternational;

    /** @var array<string, Blueprint> keyed by "scope|scopeId" */
    private array $memo = [];

    public function __construct(
        ContractDefinitions  $contractDefinitions,
        Config               $config,
        MailboxInternational $mailboxInternational
    ) {
        $this->contractDefinitions  = $contractDefinitions;
        $this->config               = $config;
        $this->mailboxInternational = $mailboxInternational;
    }

    public function forScope(string $scopeName, ?int $scopeId): Blueprint
    {
        $key = $scopeName . '|' . (int) $scopeId;

        if (! isset($this->memo[$key])) {
            $capabilities  = $this->contractDefinitions->forScope($scopeName, $scopeId);
            $international = [];

            // Without a contract there is no carrier section to put the field in, so no row to read.
            if (! $capabilities->isPermissive()) {
                $apiKey        = trim((string) $this->config->getScopedConfig(Config::XML_PATH_API_KEY, $scopeName, $scopeId));
                $international = $this->mailboxInternational->carriersFor($apiKey);
            }

            $this->memo[$key] = Generator::for($capabilities, $international);
        }

        return $this->memo[$key];
    }
}
