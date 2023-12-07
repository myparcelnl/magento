<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Pdk\Register;

use MyParcelNL\Magento\Tests\Mock\MockMagentoPdkBootstrapper;
use MyParcelNL\Pdk\Account\Platform;
use MyParcelNL\Pdk\Base\Pdk as PdkInstance;
use MyParcelNL\Magento\Pdk\Hooks\MessageManagerHook;
use MyParcelNL\Pdk\Facade\Pdk;

require dirname(__FILE__, 3) . '/vendor/autoload.php';

class BootProcess
{
    /**
     * @var \Magento\Framework\App\DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @var \MyParcelNL\Magento\Pdk\Hooks\MessageManagerHook
     */
    private $messageManager;

    /**
     * @var string
     */
    private $magentoMode;

    /**
     * @param  null $data
     */
    public function __construct(
        $data = null
    ) {
        // todo: hoe gaan we ervoor zorgen dat we Magento classes kunnen gebruiken tijdens het booten van de PDK?
        //$this->deploymentConfig = new DeploymentConfig();
        //$this->magentoMode      = $this->deploymentConfig->get('MAGE_MODE') ?? 'default';
        $this->magentoMode    = 'default';
        $this->messageManager = new MessageManagerHook([], '');
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function boot(): void
    {
        // todo: try to find a way to get nl/be from the config to rename name of the plugin
        $pluginCc = 'nl';

        $this->bootPdk(
            Platform::MYPARCEL_NAME . $pluginCc,
            'MyParcel',
            $this->getVersion(),
            dirname(__FILE__, 3),
            dirname(__FILE__, 3),
            ($this->magentoMode === 'developer')
                ? PdkInstance::MODE_DEVELOPMENT
                : PdkInstance::MODE_PRODUCTION
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
     * @throws \Exception
     */
    private function bootPdk(
        string $name,
        string $title,
        string $version,
        string $path,
        string $url,
        string $mode = \MyParcelNL\Pdk\Base\Pdk::MODE_PRODUCTION
    ): void {
        MockMagentoPdkBootstrapper::boot(...func_get_args());
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
