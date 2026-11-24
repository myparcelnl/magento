<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;

it('reads the export chunk size the way the admin field validates it', function ($stored, int $expected) {
    $config = Mockery::mock(Config::class)->makePartial();
    $config->shouldReceive('getGeneralConfig')->with('print/export_chunk_size')->andReturn($stored);

    expect($config->getExportChunkSize())->toBe($expected);
})->with([
    // 20 is the default that the admin field's tooltip promises.
    'not set'      => [null, 20],
    'empty'        => ['', 20],
    'text'         => ['abc', 20],
    'zero'         => ['0', 20],
    'negative'     => ['-1', 20],
    'decimal'      => ['50.5', 20],
    'exponent'     => ['1e2', 20],
    'above max'    => ['101', 20],
    'minimum'      => ['1', 1],
    'maximum'      => ['100', 100],
    'in range'     => ['50', 50],
]);
