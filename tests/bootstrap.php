<?php

declare(strict_types=1);

// Magento root autoloader (framework classes like AbstractCarrier, AbstractHelper, etc.)
// Path: tests/ -> MyParcelNL/Magento/ -> code/ -> app/ -> magento246/
$magentoAutoloader = __DIR__ . '/../../../../../vendor/autoload.php';
if (file_exists($magentoAutoloader)) {
    require $magentoAutoloader;
}
