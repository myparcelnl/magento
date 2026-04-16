<?php

declare(strict_types=1);

// CI: module has its own vendor/ from composer install
$moduleAutoloader = __DIR__ . '/../vendor/autoload.php';
// Local Magento: root has everything (module vendor/ should not exist)
$magentoAutoloader = __DIR__ . '/../../../../../vendor/autoload.php';

// Only one autoloader may be loaded: the module vendor ships PHPUnit 10 (via
// Pest v2) while the Magento root vendor ships PHPUnit 9. Loading both causes
// class conflicts (e.g. TestSuite::empty() missing). Prefer the module vendor
// when it exists (CI and local dev with composer install); fall back to the
// Magento root for running tests from the Magento installation without a
// module-level vendor directory.
if (file_exists($moduleAutoloader)) {
    require $moduleAutoloader;
} elseif (file_exists($magentoAutoloader)) {
    require $magentoAutoloader;
}

// Fallback autoloader: generate empty stubs for Magento classes that are not
// installed in CI (e.g. classes from magento/module-shipping, magento/module-quote).
// This lets Mockery create mocks and lets compile-time class references resolve
// without pulling in the entire Magento dependency tree.
spl_autoload_register(function (string $class): void {
    if (strpos($class, 'Magento\\') !== 0) {
        return;
    }

    $lastPart    = substr($class, strrpos($class, '\\') + 1);
    $isInterface = substr($lastPart, -9) === 'Interface';
    $ns          = substr($class, 0, strrpos($class, '\\'));
    $keyword     = $isInterface ? 'interface' : 'class';

    eval("namespace $ns; $keyword $lastPart {}");
});
