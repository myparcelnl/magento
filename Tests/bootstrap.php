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
//
// A small map of known string constants is injected into the stub body so that
// production code that references e.g. ScopeInterface::SCOPE_WEBSITES at class
// load time still resolves under tests.
$knownStubConstants = [
    'Magento\\Framework\\App\\Config\\ScopeConfigInterface' => [
        'SCOPE_TYPE_DEFAULT' => 'default',
    ],
    'Magento\\Store\\Model\\ScopeInterface' => [
        'SCOPE_WEBSITES' => 'websites',
        'SCOPE_STORES'   => 'stores',
    ],
    'Magento\\Authorization\\Model\\UserContextInterface' => [
        'USER_TYPE_INTEGRATION' => 1,
        'USER_TYPE_ADMIN'       => 2,
        'USER_TYPE_CUSTOMER'    => 3,
        'USER_TYPE_GUEST'       => 4,
    ],
];

spl_autoload_register(function (string $class) use ($knownStubConstants): void {
    if (strpos($class, 'Magento\\') !== 0) {
        return;
    }

    $lastPart    = substr($class, strrpos($class, '\\') + 1);
    $isInterface = substr($lastPart, -9) === 'Interface';
    $ns          = substr($class, 0, strrpos($class, '\\'));
    $keyword     = $isInterface ? 'interface' : 'class';

    $body = '';
    foreach ($knownStubConstants[$class] ?? [] as $name => $value) {
        $body .= sprintf('const %s = %s;', $name, var_export($value, true));
    }

    eval("namespace $ns; $keyword $lastPart { $body }");
});
