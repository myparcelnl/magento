<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Shipment\OrderShipmentOptions;

/**
 * The return description shares the parent's 45-character field, and nothing downstream clips it:
 * createReturns() hands the row through as a plain array and the generated model stores `options`
 * raw, so the only place this can be kept legal is here. The old wording could not fit at all —
 * 46 characters before the parent description was even added.
 */
function returnLabelDescriptionFor(?string $parentDescription): string
{
    return invokePrivateMethod(
        newInstanceWithoutConstructor(MagentoOrderCollection::class),
        'returnLabelDescription',
        [builtShipmentFor('key', '000000123', null, null, $parentDescription)]
    );
}

it('fits the field even when the parent description is far too long', function () {
    $result = returnLabelDescriptionFor(str_repeat('a', 200));

    expect(mb_strlen($result))->toBeLessThanOrEqual(OrderShipmentOptions::LABEL_DESCRIPTION_MAX_LENGTH);
});

it('keeps the validity date, which is what the truncation used to eat', function () {
    // Truncating the whole sentence cut the date off every time, so the label never carried the
    // one fact it was added for. Only the parent description is shortened now.
    $result = returnLabelDescriptionFor(str_repeat('a', 200));

    expect($result)->toEndWith(date('d-m-Y', strtotime('+ 28 days')));
});

it('passes a short parent description through untouched', function () {
    expect(returnLabelDescriptionFor('000000123'))
        ->toBe(sprintf('Retour 000000123 t/m %s', date('d-m-Y', strtotime('+ 28 days'))));
});

it('reads cleanly when the parent carries no description at all', function () {
    // The empty case is the one that used to be 46 characters, over the limit before anything was
    // added to it.
    expect(returnLabelDescriptionFor(null))
        ->toBe(sprintf('Retour t/m %s', date('d-m-Y', strtotime('+ 28 days'))));
});
