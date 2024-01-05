<?php

declare(strict_types=1);

/**
 * Here we can run the migrations and after that we can boot the PDK.
 */

namespace MyParcelNL\Magento\Setup;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Catalog\Setup\CategorySetup;
use MyParcelNL\Magento\Models\Boot;

class UpgradeData implements UpgradeDataInterface
{
    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    protected $moduleList;


    public function __construct(
        ModuleListInterface $moduleList
    ) {
        $this->moduleList = $moduleList;
    }

    /**
     * @throws \Exception
     */
    public function upgrade(
        ModuleDataSetupInterface $setup,
        ModuleContextInterface $context
    ): void
    {
        // todo: implement migrations and database connection

        $boot = new Boot(
            $context,
            $this->moduleList
        );

        /** @note check if PDK is installed */
        if (! $boot->isInstalled()) {

            /** @note retry boot process */
            $boot->retryProcess();

        }
    }
}
