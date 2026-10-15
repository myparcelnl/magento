<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;

it('reads a decimal as entered in any common notation', function (string $entered, float $expected) {
    expect(Config::parseDecimal($entered))->toBe($expected);
})->with([
    'dot decimal'                  => ['5.95', 5.95],
    'comma decimal'                => ['5,95', 5.95],
    'whole number'                 => ['5', 5.0],
    'surrounding whitespace'       => [' 5,95 ', 5.95],
    'negative'                     => ['-2,50', -2.5],
    'space thousands'              => ['1 234,56', 1234.56],
    'non-breaking space thousands' => ["1\u{00A0}234.56", 1234.56],
    'dot thousands, comma decimal' => ['1.234,56', 1234.56],
    'comma thousands, dot decimal' => ['1,234.56', 1234.56],
    'repeated dot thousands'       => ['1.234.567', 1234567.0],
    'repeated comma thousands'     => ['1,234,567', 1234567.0],
    'mixed, many groups'           => ['1.234.567,89', 1234567.89],
    'single dot is decimal'        => ['1.234', 1.234],
    'single comma is decimal'      => ['1,234', 1.234],
    'empty'                        => ['', 0.0],
    'not a number'                 => ['abc', 0.0],
]);
