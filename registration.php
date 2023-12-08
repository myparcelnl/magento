<?php
/**
 * @noinspection PhpIllegalPsrClassPathInspection
 * @noinspection AutoloadingIssuesInspection
 */

declare(strict_types=1);

/*
Plugin Name: MyParcelNL Magento
Plugin URI: https://github.com/myparcelnl/magento
Description: Export your Magento orders to MyParcel and print labels directly from the Magento admin
Author: MyParcel
Author URI: https://myparcel.nl
Version: dev
License: MIT
License URI: http://www.opensource.org/licenses/mit-license.php
*/

use MyParcelNL\Pdk\Base\Pdk as PdkInstance;
use MyParcelNL\Magento\Pdk\Hooks\MessageManagerHook;
use MyParcelNL\Pdk\Account\Platform;
use MyParcelNL\Pdk\Facade\Installer;
use MyParcelNL\Pdk\Facade\Pdk;
use MyParcelNL\Magento\Service\MagentoHookService;
use function MyParcelNL\Magento\bootPdk;

require(__DIR__ . '/vendor/autoload.php');

if (class_exists(MyParcelNLMagento::class)) {
    throw new RuntimeException('MyParcelNL Magento plugin already loaded');
}

final class MyParcelNLMagento
{
    /**
     * @var string
     */
    private $messageManager;

    public function __construct()
    {
        $this->messageManager = Pdk::get(MessageManagerHook::class);
    }

    /**
     * Perform required tasks that initialize the plugin.
     *
     * @throws \Throwable
     */
    public function initialize(): void
    {
        $this->boot();

        /** @var MagentoHookService $hookService */
        $hookService = Pdk::get(MagentoHookService::class);
        $hookService->applyAll();
    }

    /**
     * @return void
     * @throws \Throwable
     */
    public function install(): void
    {
        $this->boot();

        $errors = $this->checkPrerequisites();

        if (! empty($errors)) {
            $this->messageManager->showErrors($errors);
        }

        Installer::install();
    }

    /**
     * @return void
     * @throws \Exception
     * @throws \Throwable
     */
    public function uninstall(): void
    {
        $this->boot();

        Installer::uninstall();
    }

    /**
     * @throws \Throwable
     */
    private function boot(): void
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

new MyParcelNLMagento();

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    Pdk::getAppInfo()->title,
    __DIR__
);

