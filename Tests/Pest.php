<?php

declare(strict_types=1);

uses()
    ->afterEach(function () {
        // A Mockery expectation is an assertion, but PHPUnit counts only its own — so a test that
        // verifies behaviour through a spy was reported "risky: performed no assertions", which
        // hides the tests that genuinely assert nothing.
        $container = Mockery::getContainer();

        if (null !== $container) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }

        Mockery::close();
    })
    ->in('Unit');
