<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\PackageTypeResolver;
use MyParcelNL\Magento\Service\PostnlMailboxInternational;

/**
 * These three replaced a shared singleton whose mutable state leaked between carriers and between
 * carts. Nothing stops that creeping back except a test, so this is it: a service may hold its
 * injected collaborators and nothing else.
 */
$statelessServices = [
    PackageTypeResolver::class        => [PackageTypeResolver::class],
    CartShippingRules::class          => [CartShippingRules::class],
    PostnlMailboxInternational::class => [PostnlMailboxInternational::class],
];

it('holds no property that is not an injected collaborator', function (string $class) {
    $reflection  = new ReflectionClass($class);
    $injected    = array_map(
        static fn(ReflectionParameter $p): string => $p->getName(),
        $reflection->getConstructor()->getParameters()
    );
    $declared = array_map(
        static fn(ReflectionProperty $p): string => $p->getName(),
        array_filter(
            $reflection->getProperties(),
            static fn(ReflectionProperty $p): bool => $p->getDeclaringClass()->getName() === $class
        )
    );

    expect(array_values(array_diff($declared, $injected)))->toBe([]);
})->with($statelessServices);

it('declares no static property, which would outlive every request', function (string $class) {
    $static = array_map(
        static fn(ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC)
    );

    expect($static)->toBe([]);
})->with($statelessServices);

it('offers no public setter, so a caller cannot put state back', function (string $class) {
    $setters = array_values(array_filter(
        array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC)
        ),
        static fn(string $name): bool => str_starts_with($name, 'set')
    ));

    expect($setters)->toBe([]);
})->with($statelessServices);
