<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;

it('reads the export chunk size the way the admin field validates it', function ($stored, int $expected) {
    $config = Mockery::mock(Config::class)->makePartial();
    $config->shouldReceive('getGeneralConfig')->with('print/export_chunk_size')->andReturn($stored);

    expect($config->getExportChunkSize())->toBe($expected);
})->with([
    'not set'      => [null, Config::DEFAULT_EXPORT_CHUNK_SIZE],
    'empty'        => ['', Config::DEFAULT_EXPORT_CHUNK_SIZE],
    'text'         => ['abc', Config::DEFAULT_EXPORT_CHUNK_SIZE],
    'zero'         => ['0', Config::DEFAULT_EXPORT_CHUNK_SIZE],
    'negative'     => ['-1', Config::DEFAULT_EXPORT_CHUNK_SIZE],
    'decimal'      => ['20.5', Config::DEFAULT_EXPORT_CHUNK_SIZE],
    'exponent'     => ['1e2', Config::DEFAULT_EXPORT_CHUNK_SIZE],
    'above max'    => ['101', Config::DEFAULT_EXPORT_CHUNK_SIZE],
    'minimum'      => ['1', 1],
    'maximum'      => ['100', 100],
    'in range'     => ['50', 50],
]);
