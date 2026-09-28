<?php

declare(strict_types=1);

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use MyParcelNL\Magento\Controller\Adminhtml\LabelExportAction;
use MyParcelNL\Magento\Controller\Adminhtml\Order\SendMyParcelReturnMail;

it('answers POST only, because it makes a return label and mails the consumer', function () {
    // It used to implement no marker at all, and HttpMethodValidator constrains only an action that
    // implements one — so every method reached it, and a link-follower could bill a shipment.
    expect(class_implements(SendMyParcelReturnMail::class))
        ->toContain(HttpPostActionInterface::class)
        ->not->toContain(HttpGetActionInterface::class);
});

it('answers the grid JSON rather than redirecting', function () {
    // The export shell is what makes it POST-only and what renders its messages beside the grid.
    expect(SendMyParcelReturnMail::class)->toExtend(LabelExportAction::class);
});
