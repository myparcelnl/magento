<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Facade;

use MyParcelNL\Magento\Contract\MagentoServiceInterface;
use MyParcelNL\Pdk\Base\Facade;

/**
 * @method static bool getVersion() Get the WordPress version.
 * @method static void renderTable(array $rows) Renders a set of rows as a table.
 * @see \MyParcelNL\Magento\Contract\MagentoServiceInterface
 */
final class Magento extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MagentoServiceInterface::class;
    }
}
