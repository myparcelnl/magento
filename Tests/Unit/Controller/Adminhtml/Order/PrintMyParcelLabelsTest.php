<?php

declare(strict_types=1);

it('answers POST only, because fetching a label writes and mails', function () {
    expect(class_implements(MyParcelNL\Magento\Controller\Adminhtml\Order\PrintMyParcelLabels::class))
        ->toContain(Magento\Framework\App\Action\HttpPostActionInterface::class)
        ->not->toContain(Magento\Framework\App\Action\HttpGetActionInterface::class);
});

it('exports over POST as well, so a bulk selection never rides in the query string', function () {
    // HttpMethodValidator only constrains an action that implements one of the markers.
    expect(class_implements(MyParcelNL\Magento\Controller\Adminhtml\LabelExportAction::class))
        ->toContain(Magento\Framework\App\Action\HttpPostActionInterface::class)
        ->not->toContain(Magento\Framework\App\Action\HttpGetActionInterface::class);
});
