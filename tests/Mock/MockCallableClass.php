<?php

declare(strict_types=1);

namespace Mock;

class MockCallableClass
{
    public static function mockStatic(): string
    {
        return 'mocked static';
    }

    public function mock(): string
    {
        return 'mocked';
    }
}
