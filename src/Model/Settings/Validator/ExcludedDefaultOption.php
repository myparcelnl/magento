<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Validator;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Phrase;
use MyParcelNL\Magento\Service\AccountSettings\ContractDefinitions;
use MyParcelNL\Magento\Service\Config;

/**
 * Refuses to automate a shipment option that the contract says cannot ship with one already automated.
 *
 * Only `default_options`: a `delivery` toggle offers an option at checkout, where the customer picks
 * one. The option that was automated first keeps its place; when one save automates both, both are
 * refused. An unreadable contract lets the value through.
 */
class ExcludedDefaultOption implements SettingValidatorInterface
{
    private const FIELD = '#^[^/]+/default_options/([a-z0-9_]+)_active$#';

    private ContractDefinitions $contractDefinitions;

    private RequestInterface $request;

    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        ContractDefinitions  $contractDefinitions,
        RequestInterface     $request,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->contractDefinitions = $contractDefinitions;
        $this->request             = $request;
        $this->scopeConfig         = $scopeConfig;
    }

    public function handles(string $path): bool
    {
        return null !== Config::carrierFromPath($path) && 1 === preg_match(self::FIELD, $path);
    }

    public function validate(string $path, $value, string $scopeName, int $scopeId): ?Phrase
    {
        $carrier = Config::carrierFromPath($path);

        if ('1' !== (string) $value || null === $carrier || ! preg_match(self::FIELD, $path, $match)) {
            return null;
        }

        $capabilities = $this->contractDefinitions->forScope($scopeName, $scopeId);

        if ($capabilities->isPermissive()) {
            return null;
        }

        $option      = $match[1];
        $wasOnBefore = '1' === $this->storedValue($path, $scopeName, $scopeId);

        foreach ($capabilities->allOptionsFor($carrier) as $other) {
            $excluded = in_array($other, $capabilities->excludesFor($carrier, null, $option), true)
                        || in_array($option, $capabilities->excludesFor($carrier, null, $other), true);

            if (! $excluded) {
                continue;
            }

            $otherPath = Config::carrierPath($carrier) . "default_options/{$other}_active";

            if ('1' !== $this->valueAfterSave($otherPath, $scopeName, $scopeId)) {
                continue;
            }

            // The one automated first stays; a pair that arrives together is refused as a pair.
            if ($wasOnBefore && '1' !== $this->storedValue($otherPath, $scopeName, $scopeId)) {
                continue;
            }

            return __(
                'Automate "%1" was not saved: it cannot ship together with "%2", which is already automated.',
                str_replace('_', ' ', $option),
                str_replace('_', ' ', $other)
            );
        }

        return null;
    }

    /** What this save posts for the field, or what it inherits when it posts none. */
    private function valueAfterSave(string $path, string $scopeName, int $scopeId): ?string
    {
        $posted = $this->request->getParam('config', [])[$path] ?? null;

        if (is_array($posted) && '1' !== ($posted['inherit'] ?? '') && array_key_exists('value', $posted)) {
            return (string) $posted['value'];
        }

        return $this->storedValue($path, $scopeName, $scopeId);
    }

    /** Read before ConfigChange reloads the config, so this is the value from before the save. */
    private function storedValue(string $path, string $scopeName, int $scopeId): ?string
    {
        $value = $this->scopeConfig->getValue($path, $scopeName, $scopeId);

        return null === $value ? null : (string) $value;
    }
}
