<?php

declare(strict_types=1);

use MyParcelNL\Magento\Block\Sales\OrdersAction;
use MyParcelNL\Magento\Controller\Adminhtml\Order\PrintMyParcelLabels;

/**
 * The label JS learns the name of the warning header from the page, so the controller holds the only
 * spelling of it.
 */

it('hands the label JS the header the label controller sets', function () {
    $block = (new ReflectionClass(OrdersAction::class))->newInstanceWithoutConstructor();

    expect($block->getLabelWarningHeader())->toBe(PrintMyParcelLabels::HEADER_INCOMPLETE);
});

it('passes the header name to the mass action on every page that starts it', function (string $template) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/view/adminhtml/templates/' . $template);

    expect($source)->toContain('"label_warning_header": "<?= $escaper->escapeJs($block->getLabelWarningHeader()) ?>"');
})->with(['order.phtml', 'order_view_action.phtml', 'shipment.phtml', 'shipment_view_action.phtml']);

it('keeps no spelling of the header in the label JS', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/view/adminhtml/web/js/label-download.js');

    expect($source)->not->toContain(PrintMyParcelLabels::HEADER_INCOMPLETE);
});
