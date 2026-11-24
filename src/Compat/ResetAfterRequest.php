<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Compat;

/**
 * Stand-in for Magento\Framework\ObjectManager\ResetAfterRequestInterface, which Magento 2.4.6 does not have.
 * registration.php aliases it to that name only when Magento lacks the interface.
 *
 * TODO INT-2124: remove this file when Magento 2.4.7 is the minimum.
 */
interface ResetAfterRequest
{
    public function _resetState(): void;
}
