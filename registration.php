<?php

declare(strict_types=1);

namespace MyParcelNL\Magento;

use Exception;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;

try {
    $bootProcess = new \MyParcelNL\Magento\Pdk\Register\BootProcess([
        'reader' => 'db/connection/default/host',
    ]);
} catch (FileSystemException|RuntimeException $e) {
    echo $e->getMessage();
}

try {
    $bootProcess->boot();
} catch (Exception $e) {
    echo $e->getMessage();
}

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'MyParcelNL_Magento',
    __DIR__
);
