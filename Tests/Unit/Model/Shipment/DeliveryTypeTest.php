<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesDeliveryTypeV2;

it('prices every SDK delivery type per type, except the ones priced as a fee', function () {
    $known = array_filter(array_map([DeliveryType::class, 'fromV2Name'], RefTypesDeliveryTypeV2::getAllowableEnumValues()));

    expect(DeliveryType::pricedPerTypeNames())->toEqualCanonicalizing(
        array_diff($known, [DeliveryType::SAME_DAY_NAME, DeliveryType::EXPRESS_NAME, DeliveryType::EARLY_MORNING_NAME])
    );
});

it('has a title for every delivery type', function () {
    expect(array_keys(DeliveryType::TITLES))->toEqualCanonicalizing(array_keys(DeliveryType::NAMES_IDS_MAP));
});
