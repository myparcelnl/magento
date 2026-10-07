<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Config;

use Magento\Config\Model\Config\PathValidator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Service\Settings;

/**
 * Lets config:set and config:show accept the paths of the generated settings form.
 *
 * Magento validates a path against system.xml only, which holds no MyParcel field. The validator
 * gets no scope, so a path passes when the form offers it at any scope. A CLI save does not run
 * the ConfigChange observer, so its setting validators and the api key import do not run.
 */
class GeneratedSettingsPathValidator
{
    private const SECTION_PREFIX = 'myparcelnl_magento_';

    private Settings              $settings;
    private StoreManagerInterface $storeManager;

    public function __construct(Settings $settings, StoreManagerInterface $storeManager)
    {
        $this->settings     = $settings;
        $this->storeManager = $storeManager;
    }

    /**
     * @param  string $path
     * @return true
     */
    public function aroundValidate(PathValidator $subject, callable $proceed, $path)
    {
        $path = (string) $path;

        if (0 === strpos($path, self::SECTION_PREFIX) && Settings::isWritable($path) && $this->isOffered($path)) {
            return true;
        }

        return $proceed($path);
    }

    private function isOffered(string $path): bool
    {
        $scopes = [[ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0]];

        foreach ($this->storeManager->getWebsites() as $website) {
            $scopes[] = [ScopeInterface::SCOPE_WEBSITES, (int) $website->getId()];
        }

        foreach ($this->storeManager->getStores() as $store) {
            $scopes[] = [ScopeInterface::SCOPE_STORES, (int) $store->getId()];
        }

        foreach ($scopes as [$scopeName, $scopeId]) {
            if (in_array($path, $this->settings->getAllFieldPaths($scopeName, $scopeId), true)) {
                return true;
            }
        }

        return false;
    }
}
