<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Pdk\Plugin\Installer;

use MyParcelNL\Pdk\App\Installer\Contract\MigrationServiceInterface;

class MagentoMigrationService implements MigrationServiceInterface
{
    public function all(): array
    {
        return [];
    }
}
