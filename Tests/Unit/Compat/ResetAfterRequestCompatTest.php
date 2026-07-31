<?php

declare(strict_types=1);

use MyParcelNL\Magento\Compat\ResetAfterRequestInterface as CompatResetAfterRequestInterface;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;

const FRAMEWORK_INTERFACE = 'Magento\Framework\ObjectManager\ResetAfterRequestInterface';

/**
 * Magento\Framework\ObjectManager\ResetAfterRequestInterface does not exist below
 * magento/framework 103.0.8, while composer.json still allows >=101.0.8. Implementing it
 * directly makes the class fatal on load, which takes down every REST request that touches
 * the user-context chain — including the checkout's delivery_options/config call.
 */
it('TokenScopeContext implements the compat interface, not the framework one directly', function () {
    expect(class_implements(TokenScopeContext::class))
        ->toHaveKey(CompatResetAfterRequestInterface::class);
});

it('no source file references the framework interface directly', function () {
    $srcDir   = dirname(__DIR__, 3) . '/src';
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));

    foreach ($files as $file) {
        if (! $file->isFile() || 'php' !== $file->getExtension()) {
            continue;
        }
        // The shim itself is the one place allowed to name it, behind interface_exists().
        if (str_ends_with(str_replace('\\', '/', $file->getPathname()), 'src/Compat/ResetAfterRequestInterface.php')) {
            continue;
        }
        if (str_contains((string) file_get_contents($file->getPathname()), FRAMEWORK_INTERFACE)) {
            $offenders[] = substr($file->getPathname(), strlen($srcDir) + 1);
        }
    }

    expect($offenders)->toBe([]);
});

it('the compat interface declares _resetState so callers keep a stable contract', function () {
    expect(interface_exists(CompatResetAfterRequestInterface::class))->toBeTrue()
        ->and(method_exists(CompatResetAfterRequestInterface::class, '_resetState'))->toBeTrue();
});

it('the compat interface extends the framework one when that exists, so DI still resets state', function () {
    if (! interface_exists(FRAMEWORK_INTERFACE)) {
        expect(class_implements(CompatResetAfterRequestInterface::class))->toBe([]);

        return;
    }

    expect(class_implements(CompatResetAfterRequestInterface::class))
        ->toHaveKey(FRAMEWORK_INTERFACE);
});
