<?php

declare(strict_types=1);

/**
 * Invokes a private or protected method directly, for behaviours that live
 * inside methods too small or too coupled to justify making them public
 * just to test them (e.g. TrackTraceHolder's precedence/arithmetic helpers).
 */
function invokePrivateMethod(object $object, string $method, array $args = [])
{
    $method = new ReflectionMethod($object, $method);
    $method->setAccessible(true);

    return $method->invokeArgs($object, $args);
}

/**
 * Sets a private, protected, or public property directly, bypassing any
 * constructor logic. Used together with newInstanceWithoutConstructor() to
 * wire only the collaborators a given test actually needs.
 */
function setPrivateProperty(object $object, string $property, $value): void
{
    // Walks up the hierarchy: a private property declared on a parent is invisible to
    // ReflectionProperty when addressed through a subclass, which is how a block under test that
    // declares its own accessors would otherwise fail.
    for ($class = new ReflectionClass($object); $class; $class = $class->getParentClass()) {
        if ($class->hasProperty($property)) {
            $reflected = $class->getProperty($property);
            $reflected->setAccessible(true);
            $reflected->setValue($object, $value);

            return;
        }
    }

    throw new ReflectionException(sprintf('Property %s::$%s does not exist', get_class($object), $property));
}

/**
 * Builds an instance without running its constructor, for classes whose
 * constructor pulls in collaborators (live ObjectManager singletons, heavy
 * SDK setup) that are irrelevant to the single method under test.
 */
function newInstanceWithoutConstructor(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}
