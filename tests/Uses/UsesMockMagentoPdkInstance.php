<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\tests\Uses;

use MyParcelNL\Magento\src\Pdk\Audit\Repository\MagentoPdkAuditRepository;
use MyParcelNL\Magento\src\Pdk\Plugin\Repository\MagentoOrderNoteRepository;
use MyParcelNL\Magento\src\Pdk\Plugin\Repository\PdkOrderRepository;
use MyParcelNL\Magento\src\Pdk\Product\Repository\MagentoPdkProductRepository;
use MyParcelNL\Magento\src\Service\MagentoCronService;
use MyParcelNL\Magento\Tests\Mock\MockMagentoPdkBootstrapper;
use MyParcelNL\Pdk\App\Order\Contract\PdkOrderNoteRepositoryInterface;
use MyParcelNL\Pdk\App\Order\Contract\PdkOrderRepositoryInterface;
use MyParcelNL\Pdk\App\Order\Contract\PdkProductRepositoryInterface;
use MyParcelNL\Pdk\Audit\Contract\PdkAuditRepositoryInterface;
use MyParcelNL\Pdk\Base\Contract\CronServiceInterface;
use MyParcelNL\Pdk\Tests\Bootstrap\MockPdkConfig;
use MyParcelNL\Pdk\Tests\Uses\UsesEachMockPdkInstance;
use function DI\get;

final class UsesMockMagentoPdkInstance extends UsesEachMockPdkInstance
{
    /**
     * @throws \Exception
     */
    protected function setup(): void
    {
        $pluginFile = __DIR__ . '/../../registration.php';

        MockMagentoPdkBootstrapper::setConfig(MockPdkConfig::create($this->getConfig()));

        MockMagentoPdkBootstrapper::boot(
            'myparcelnl',
            'MyParcel [TEST]',
            '0.0.1',
            sprintf('%s/', dirname($pluginFile)),
            'https://my-site/'
        );
    }

    /**
     * @return array
     */
    private function getConfig(): array
    {
        return array_replace(
            $this->config,
            [
                CronServiceInterface::class            => get(MagentoCronService::class),
                //MagentoDatabaseServiceInterface::class      => get(MockMagentoDatabaseService::class),
                PdkOrderNoteRepositoryInterface::class => get(MagentoOrderNoteRepository::class),
                PdkAuditRepositoryInterface::class     => get(MagentoPdkAuditRepository::class),
                PdkOrderRepositoryInterface::class     => get(PdkOrderRepository::class),
                PdkProductRepositoryInterface::class   => get(MagentoPdkProductRepository::class),
            ]
        );
    }
}
