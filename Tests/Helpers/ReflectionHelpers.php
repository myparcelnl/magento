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
    $property = new ReflectionProperty($object, $property);
    $property->setAccessible(true);
    $property->setValue($object, $value);
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
