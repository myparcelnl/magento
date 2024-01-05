<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use MyParcelNL\Magento\Models\Boot;

class Watcher implements ObserverInterface
{
    private $context;

    private $moduleList;

    public function __construct(
        ModuleContextInterface $context,
        ModuleListInterface $moduleList
    )
    {
        $this->context = $context;
        $this->moduleList = $moduleList;
    }

    /**
     * @throws \Exception
     */
    public function execute(Observer $observer): void
    {
        $boot = new Boot(
            $this->context,
            $this->moduleList
        );

        /** @note check if PDK is installed */
        if (! $boot->isInstalled()) {

            /** @note retry boot process */
            $boot->retryProcess();

        }
    }
}
