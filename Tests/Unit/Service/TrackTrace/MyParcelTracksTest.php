<?php

declare(strict_types=1);

use Magento\Framework\DataObject;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Service\TrackTrace\MyParcelTracks;

/**
 * isOwn() answers about an already-loaded row, for a caller handed a collection it did not query.
 */
it('claims a track carrying the module carrier code', function () {
    expect(MyParcelTracks::isOwn(['carrier_code' => Carrier::CODE]))->toBeTrue();
});

it('disowns another carrier the order also shipped with', function () {
    expect(MyParcelTracks::isOwn(['carrier_code' => 'dhl']))->toBeFalse();
});

it('claims a row with no carrier code, which is a track this pass just minted', function () {
    expect(MyParcelTracks::isOwn([]))->toBeTrue()
        ->and(MyParcelTracks::isOwn(['carrier_code' => null]))->toBeTrue();
});

it('reads a DataObject the same way as an array', function () {
    expect(MyParcelTracks::isOwn(new DataObject(['carrier_code' => Carrier::CODE])))->toBeTrue()
        ->and(MyParcelTracks::isOwn(new DataObject(['carrier_code' => 'dhl'])))->toBeFalse();
});
