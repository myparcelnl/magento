<?php

declare(strict_types=1);

use MyParcelNL\Magento\Controller\Adminhtml\LabelExportAction;
use MyParcelNL\Magento\Controller\Adminhtml\Order\SendMyParcelReturnMail;

it('extends the label export shell, so it is POST-only and answers beside the grid', function () {
    // It makes a return label and mails the consumer, so a link-follower must not reach it over GET.
    // PrintMyParcelLabelsTest pins that LabelExportAction answers POST only.
    expect(SendMyParcelReturnMail::class)->toExtend(LabelExportAction::class);
});
