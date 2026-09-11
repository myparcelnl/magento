<?php

declare(strict_types=1);

use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stubs the static ObjectManager that the Logger facade resolves through.
 *
 * The instance is global, so call this inside the test that needs it, not in a shared setup.
 *
 * @param array<class-string, mixed> $alsoBind extra bindings resolved from the same singleton
 */
function mockLoggerFacade(array $alsoBind = []): LoggerInterface
{
    $logger = Mockery::mock(LoggerInterface::class);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(LoggerInterface::class)->andReturn($logger);

    foreach ($alsoBind as $class => $instance) {
        $objectManager->shouldReceive('get')->with($class)->andReturn($instance);
    }

    ObjectManager::setInstance($objectManager);

    return $logger;
}
