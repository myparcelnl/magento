<?php

declare(strict_types=1);

uses()
    ->afterEach(function () {
        // A Mockery expectation is an assertion, but PHPUnit counts only its own. Without this, a
        // test that verifies through a spy is "risky: performed no assertions", which hides the
        // tests that really assert nothing.
        $container = Mockery::getContainer();

        if (null !== $container) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }

        Mockery::close();
    })
    ->in('Unit');
