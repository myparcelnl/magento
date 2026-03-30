<?php

declare(strict_types=1);

// CI: module has its own vendor/ from composer install
$moduleAutoloader = __DIR__ . '/../vendor/autoload.php';
// Local Magento: root has everything (module vendor/ should not exist)
$magentoAutoloader = __DIR__ . '/../../../../../vendor/autoload.php';

if (file_exists($magentoAutoloader)) {
    require $magentoAutoloader;
}
if (file_exists($moduleAutoloader)) {
    require $moduleAutoloader;
}
