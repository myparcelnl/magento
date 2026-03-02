<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest;

abstract class AbstractVersionedResource
{
    abstract public static function getVersion(): int;

    abstract public function format(): array;
}
