<?php
/**
 * @noinspection PhpIllegalPsrClassPathInspection
 * @noinspection AutoloadingIssuesInspection
 */

declare(strict_types=1);

/*
Plugin Name: MyParcelNL Magento
Plugin URI: https://github.com/myparcelnl/magento
Description: Export your Magento orders to MyParcel and print labels directly from the Magento admin
Author: MyParcel
Author URI: https://myparcel.nl
Version: dev
License: MIT
License URI: http://www.opensource.org/licenses/mit-license.php
*/

use Magento\Framework\App\DeploymentConfig;
use MyParcelNL\Pdk\Base\Pdk as PdkInstance;
use MyParcelNL\Magento\Pdk\Hooks\MessageManagerHook;
use MyParcelNL\Pdk\Account\Platform;
use MyParcelNL\Pdk\Facade\Installer;
use MyParcelNL\Pdk\Facade\Pdk;
use MyParcelNL\Magento\Service\MagentoHookService;
use function MyParcelNL\Magento\bootPdk;
use Magento\Framework\App\ObjectManager;

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'MyParcelNL_Magento',
    __DIR__
);
