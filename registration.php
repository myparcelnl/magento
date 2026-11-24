<?php

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'MyParcelNL_Magento',
    __DIR__
);

// Magento 2.4.6 has no ResetAfterRequestInterface (added in 2.4.7), which two of our classes implement.
// TODO INT-2124: remove this block when Magento 2.4.7 is the minimum.
if (! interface_exists(\Magento\Framework\ObjectManager\ResetAfterRequestInterface::class)) {
    require_once __DIR__ . '/src/Compat/ResetAfterRequest.php';
    class_alias(
        \MyParcelNL\Magento\Compat\ResetAfterRequest::class,
        \Magento\Framework\ObjectManager\ResetAfterRequestInterface::class
    );
}
