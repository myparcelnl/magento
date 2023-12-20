<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Models;

use Magento\Framework\App\DeploymentConfig;
use MyParcelNL\Pdk\Base\Pdk as PdkInstance;
use MyParcelNL\Magento\Pdk\Hooks\MessageManagerHook;
use MyParcelNL\Pdk\Account\Platform;
use MyParcelNL\Pdk\Facade\Installer;
use MyParcelNL\Pdk\Facade\Pdk;
use MyParcelNL\Magento\Service\MagentoHookService;
use function MyParcelNL\Magento\bootPdk;
use Magento\Framework\App\ObjectManager;

class Boot
{
    public function __construct()
    {
        $this->boot();

        /** @var MagentoHookService $hookService */
        $hookService = Pdk::get(MagentoHookService::class);
        $hookService->applyAll();
    }

    private function boot()
    {
        // todo: try to find a way to get nl/be from the config to rename name of the plugin
        $pluginCc = 'nl';

        bootPdk(
            Platform::MYPARCEL_NAME . $pluginCc,
            'MyParcel',
            $this->getVersion(),
            dirname(__FILE__, 3),
            dirname(__FILE__, 3),
            PdkInstance::MODE_DEVELOPMENT
        );

        $myParcelMagentoVersion = sprintf('MYPARCEL%s_MAGENTO_VERSION', strtoupper($pluginCc));
        if (! defined($myParcelMagentoVersion)) {
            define($myParcelMagentoVersion, $this->getVersion());
        }

        $errors = $this->checkPrerequisites();

        if (! empty($errors)) {
            $this->messageManager->apply([
                MessageManagerHook::MESSAGE_ERROR,
                implode('<br>', $errors),
            ]);
        }
    }

    /**
     * @return string
     */
    private function getVersion(): string
    {
        $composerVersion = json_decode(file_get_contents(dirname(__FILE__, 3) . '/composer.json'), true);

        return $composerVersion['version'];
    }

    /**
     * Check if the minimum requirements are met.
     *
     * @return array
     */
    private function checkPrerequisites(): array
    {
        $errors = [];

        if (! Pdk::get('isPhpVersionSupported')) {
            $errors[] = Pdk::get('errorMessagePhpVersion');
        }

        return $errors;
    }
}
