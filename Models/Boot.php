<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Models;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Setup\Model\ModuleContext;
use MyParcelNL\Magento\src\Pdk\Hooks\MessageManagerHook;
use MyParcelNL\Magento\src\Service\MagentoHookService;
use MyParcelNL\Pdk\Base\Pdk as PdkInstance;
use MyParcelNL\Pdk\Account\Platform;
use MyParcelNL\Pdk\Facade\Pdk;
use function MyParcelNL\Magento\bootPdk;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Message\ManagerInterface;

class Boot
{
    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var ModuleContextInterface
     */
    private $context;

    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    /**
     * @throws \Exception
     */
    public function __construct(
        ModuleContextInterface $context,
        ModuleListInterface $moduleList
    )
    {
        /** @var ManagerInterface $messageManager */
        $this->messageManager = ObjectManager::getInstance()->get(ManagerInterface::class);

        $this->context = $context;
        $this->moduleList = $moduleList;

        /** @note boot PDK */
        $this->boot();

        /** @var MagentoHookService $hookService */
        $hookService = Pdk::get(MagentoHookService::class);
        $hookService->applyAll();
    }

    /**
     * @throws \Exception
     */
    private function boot(): void
    {
        $pluginCc = $this->moduleList
            ->getOne('MyParcelNL_Magento')['cc'] ?? 'NL';

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
