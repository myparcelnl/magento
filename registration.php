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

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'MyParcelNL_Magento',
    __DIR__
);
