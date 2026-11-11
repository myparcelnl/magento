<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Framework\App\Cache\Manager;
use Magento\Framework\App\Cache\State;
use Magento\Framework\App\DeploymentConfig;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Cache\Type\Capabilities;
use MyParcelNL\Magento\Service\LogContext;
use Throwable;

/**
 * Turns the capabilities cache type on for an install that predates it.
 *
 * setup:upgrade does not add a new cache type to app/etc/env.php, and Magento disables a type that
 * is not there. This writes only when the type is not in env.php, so a type an admin switched off
 * stays off.
 */
class EnableCapabilitiesCache
{
    private Manager          $cacheManager;
    private DeploymentConfig $deploymentConfig;

    public function __construct(Manager $cacheManager, DeploymentConfig $deploymentConfig)
    {
        $this->cacheManager     = $cacheManager;
        $this->deploymentConfig = $deploymentConfig;
    }

    public function run(): void
    {
        try {
            // Manager::getStatus() lists every type declared in cache.xml, so it cannot tell an
            // absent one from a disabled one. env.php can.
            $configured = $this->deploymentConfig->get(State::CACHE_KEY) ?? [];

            if (array_key_exists(Capabilities::TYPE_IDENTIFIER, $configured)) {
                return;
            }

            $this->cacheManager->setEnabled([Capabilities::TYPE_IDENTIFIER], true);
        } catch (Throwable $e) {
            // A read-only app/etc is a deployment choice, not a reason to fail an upgrade. Say so
            // loudly enough that the missing cache is explainable later.
            Logger::warning(
                sprintf(
                    'Could not enable the %s cache type. Enable it with "bin/magento cache:enable %s", '
                    . 'or capability lookups stay uncached.',
                    Capabilities::TYPE_IDENTIFIER,
                    Capabilities::TYPE_IDENTIFIER
                ),
                LogContext::of($e)
            );
        }
    }
}
