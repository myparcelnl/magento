<?php

declare(strict_types=1);

/** Invokes a private or protected method directly. */
function invokePrivateMethod(object $object, string $method, array $args = [])
{
    $method = new ReflectionMethod($object, $method);
    $method->setAccessible(true);

    return $method->invokeArgs($object, $args);
}

/** Sets a property of any visibility, also one declared private on a parent class. */
function setPrivateProperty(object $object, string $property, $value): void
{
    // a private property of a parent is invisible to ReflectionProperty through the subclass
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

/** Builds an instance without its constructor, to wire only the collaborators a test needs. */
function newInstanceWithoutConstructor(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}
