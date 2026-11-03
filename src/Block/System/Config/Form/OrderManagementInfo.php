<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Settings;
use MyParcelNL\Sdk\Client\Generated\IamApi\Model\Feature;

/**
 * Read-only, as bare JSON: the order management of the account behind this scope's api key, and its
 * stored features. For checking what the export decides on, so it is deliberately not pretty.
 *
 * DynamicSettings renders a field block through toHtml(), not render(), so the output is _toHtml().
 */
class OrderManagementInfo extends Template
{
    private Settings      $settings;
    private Config        $config;
    private StoredAccount $storedAccount;

    public function __construct(
        Context       $context,
        Settings      $settings,
        Config        $config,
        StoredAccount $storedAccount,
        array         $data = []
    ) {
        parent::__construct($context, $data);
        $this->settings      = $settings;
        $this->config        = $config;
        $this->storedAccount = $storedAccount;
    }

    protected function _toHtml(): string
    {
        [$scopeName, $scopeId] = $this->settings->getCurrentScopeFromRequest($this->getRequest());
        $apiKey = (string) $this->config->getScopedConfig(Config::XML_PATH_API_KEY, $scopeName, $scopeId);

        return '<pre>' . $this->_escaper->escapeHtml((string) json_encode(
            self::summary($apiKey, '' === $apiKey ? null : $this->storedAccount->featuresForApiKey($apiKey)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        )) . '</pre>';
    }

    /**
     * @param string[]|null $features null when the stored row has none yet
     *
     * @return array{api_key: bool, order_management: string|null, features: string[]|null}
     */
    public static function summary(string $apiKey, ?array $features): array
    {
        $orderManagement = null;

        if (null !== $features) {
            $orderManagement = in_array(Feature::LEGACY_ORDER_MANAGEMENT, $features, true)
                ? 'v1'
                : (in_array(Feature::ORDER_MANAGEMENT, $features, true) ? 'v2' : 'none');
        }

        return ['api_key' => '' !== $apiKey, 'order_management' => $orderManagement, 'features' => $features];
    }
}
