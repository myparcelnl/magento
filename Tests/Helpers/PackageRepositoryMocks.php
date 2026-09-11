<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;

/**
 * A PackageRepository with its protected config and product readers open to stubbing.
 *
 * Only the construction is shared. Each caller stubs getConfigValue() its own way, because the
 * cases differ on whether the store id, the whole path, or neither is the key.
 *
 * @return PackageRepository|Mockery\MockInterface
 */
function makePackageRepository(): PackageRepository
{
    return Mockery::mock(PackageRepository::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
}
