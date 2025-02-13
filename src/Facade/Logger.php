<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Facade;

use Psr\Log\LoggerInterface;

/**
 * @method static void emergency(string $message, array $context = [])
 * @method static void alert(string $message, array $context = [])
 * @method static void critical(string $message, array $context = [])
 * @method static void error(string $message, array $context = [])
 * @method static void warning(string $message, array $context = [])
 * @method static void notice(string $message, array $context = [])
 * @method static void info(string $message, array $context = [])
 * @method static void debug(string $message, array $context = [])
 * @method static void log($level, string $message, array $context = [])
 * @see LoggerInterface
 */
class Logger extends Facade
{
    public static function getFacadeAccessor(): string
    {
        return LoggerInterface::class;
    }
}
