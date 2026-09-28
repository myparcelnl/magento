<?php

declare(strict_types=1);

/**
 * Every dispatchable admin controller must name its own ACL resource.
 *
 * A controller without ADMIN_RESOURCE inherits Magento_Backend::admin, the ACL root that almost
 * every custom admin role ends up holding, so the resource declared in etc/acl.xml is never
 * consulted and the action is effectively ungated. The failure is silent: the controller works.
 */

/**
 * Keyed by fully qualified name: Order\ and Shipment\ share short class names, and a short-name key
 * lets one overwrite the other.
 *
 * @return array<class-string, array{parent: ?string, declares: bool, dispatchable: bool}>
 */
function adminControllerGraph(): array
{
    $root  = dirname(__DIR__, 4) . '/Controller/Adminhtml';
    $graph = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if ('php' !== $file->getExtension()) {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (! preg_match('/^(abstract )?class (\w+)(?: extends ([\w\\\\]+))?/m', $source, $class)) {
            continue;
        }

        $namespace = preg_match('/^namespace ([\w\\\\]+);/m', $source, $match) ? $match[1] : '';

        $graph[$namespace . '\\' . $class[2]] = [
            'parent'       => isset($class[3]) ? qualifiedName($class[3], $namespace, $source) : null,
            'declares'     => false !== strpos($source, 'ADMIN_RESOURCE'),
            // Concrete is enough: a controller that inherits execute() is just as dispatchable.
            'dispatchable' => '' === $class[1],
        ];
    }

    return $graph;
}

/** The class a name in $source refers to: its `use` import (alias included), else its own namespace. */
function qualifiedName(string $name, string $namespace, string $source): string
{
    if ('\\' === $name[0]) {
        return ltrim($name, '\\');
    }

    preg_match_all('/^use ([\w\\\\]+?)(?: as (\w+))?;/m', $source, $imports, PREG_SET_ORDER);

    foreach ($imports as $import) {
        $alias = $import[2] ?? '';

        if ($name === ('' !== $alias ? $alias : substr(strrchr('\\' . $import[1], '\\'), 1))) {
            return $import[1];
        }
    }

    return $namespace . '\\' . $name;
}

function resolvesAdminResource(string $class, array $graph): bool
{
    while (isset($graph[$class])) {
        if ($graph[$class]['declares']) {
            return true;
        }

        $class = $graph[$class]['parent'] ?? '';
    }

    return false;
}

it('finds the admin controllers', function () {
    expect(adminControllerGraph())->not->toBeEmpty();
});

it('keys the graph by fully qualified name', function () {
    expect(adminControllerGraph())
        ->toHaveKey(MyParcelNL\Magento\Controller\Adminhtml\Order\CreateAndPrintMyParcelTrack::class)
        ->toHaveKey(MyParcelNL\Magento\Controller\Adminhtml\Shipment\CreateAndPrintMyParcelTrack::class);
});

it('resolves a parent through its import', function () {
    expect(adminControllerGraph()[MyParcelNL\Magento\Controller\Adminhtml\Order\CreateAndPrintMyParcelTrack::class]['parent'])
        ->toBe(MyParcelNL\Magento\Controller\Adminhtml\LabelExportAction::class);
});

it('gates every dispatchable admin controller behind its own ACL resource', function () {
    $graph   = adminControllerGraph();
    $ungated = [];

    foreach ($graph as $class => $node) {
        if ($node['dispatchable'] && ! resolvesAdminResource($class, $graph)) {
            $ungated[] = $class;
        }
    }

    expect($ungated)->toBe([]);
});

/**
 * Loads the classes, which the file scan above deliberately does not.
 *
 * A controller constant built from Magento constants (ScopeInterface::SCOPE_WEBSITE and friends)
 * fatals in CI, where module-store is absent and Tests/bootstrap.php stubs the interface. Only
 * evaluating the constants catches that.
 */
it('evaluates every admin controller constant under the CI stubs', function (string $class) {
    expect((new ReflectionClass($class))->getConstants())->toHaveKey('ADMIN_RESOURCE');
})->with([
    MyParcelNL\Magento\Controller\Adminhtml\Settings\CarrierConfigurationImport::class,
    MyParcelNL\Magento\Controller\Adminhtml\Order\SendMyParcelReturnMail::class,
    MyParcelNL\Magento\Controller\Adminhtml\LabelExportAction::class,
]);
