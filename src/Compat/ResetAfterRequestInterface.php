<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Compat;

/**
 * Forward-compat shim: Magento\Framework\ObjectManager\ResetAfterRequestInterface was added
 * after Magento 2.4.6. On older installations this interface simply marks the class as
 * resettable without the framework calling _resetState() automatically; on newer installations
 * we extend the real interface so the object manager does call it between requests.
 */
if (interface_exists(\Magento\Framework\ObjectManager\ResetAfterRequestInterface::class)) {
    // phpcs:ignore PSR1.Classes.ClassDeclaration
    interface ResetAfterRequestInterface extends \Magento\Framework\ObjectManager\ResetAfterRequestInterface {}
} else {
    // phpcs:ignore PSR1.Classes.ClassDeclaration
    interface ResetAfterRequestInterface
    {
        public function _resetState(): void;
    }
}
