<?php

declare(strict_types=1);

namespace MyParcelNL\Magento;

use MyParcelNL\Pdk\Base\Pdk;
use MyParcelNL\Magento\Pdk\MagentoPdkBootstrapper;
use MyParcelNL\Magento\Tests\Mock\MockMagentoPdkBootstrapper;

if (! function_exists('\MyParcelNL\Magento\bootPdk')) {
    /**
     * @param  string $name
     * @param  string $title
     * @param  string $version
     * @param  string $path
     * @param  string $url
     * @param  string $mode
     *
     * @return void
     * @throws \Exception
     */
    function bootPdk(
        string $name,
        string $title,
        string $version,
        string $path,
        string $url,
        string $mode = Pdk::MODE_PRODUCTION
    ): void {
        // TODO: find a way to make this work without having this in production code
        if (! defined('PEST')) {
            MagentoPdkBootstrapper::boot(...func_get_args());

            return;
        }

        MockMagentoPdkBootstrapper::boot(...func_get_args());
    }
}
