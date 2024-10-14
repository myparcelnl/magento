<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Facade;

use Magento\Framework\App\ObjectManager;

abstract class Facade
{
    public static function __callStatic(string $method, $args)
    {
        return static::getFacadeRoot()
                     ->$method(
                         ...$args
                     );
    }

    protected static function getFacadeRoot()
    {
        return ObjectManager::getInstance()->get(static::getFacadeAccessor());
    }

    abstract public static function getFacadeAccessor();
}
