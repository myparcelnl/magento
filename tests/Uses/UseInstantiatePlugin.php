<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\tests\Uses;

use Magento\Framework\Module\ModuleListInterface;
use MyParcelNL\Pdk\Tests\Uses\BaseMock;

final class UseInstantiatePlugin implements BaseMock
{
    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    private $moduleList;

    /**
     * @param  null|\Magento\Framework\Module\ModuleListInterface $moduleList
     */
    public function __construct(
        ModuleListInterface $moduleList = null
    )
    {
        $this->moduleList = $moduleList;
    }

    public function beforeEach(): void
    {
        $plugin = $this->moduleList->getOne('MyParcelNL_Magento');
        if ($plugin) {
            return;
        }

        require __DIR__ . '/../../registration.php';
    }
}
