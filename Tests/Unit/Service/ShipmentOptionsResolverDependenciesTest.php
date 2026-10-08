<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\OptionSource;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

it('adds the companions an option requires, and says so', function () {
    $notices = captureNotices();

    $resolved = dependencyResolver(acceptanceOptionsFor('POSTNL'), [ShipmentOption::AGE_CHECK => OptionSource::CONFIGURATION])
        ->resolve()->toArray();

    expect($resolved[ShipmentOption::SIGNATURE])->toBeTrue()
        ->and($resolved[ShipmentOption::ONLY_RECIPIENT])->toBeTrue()
        ->and($notices->getArrayCopy())->toContain(
            'Shipment option signature added to order 100000001, because age_check requires it.'
        );
});

it('follows requires one level only, so receipt code keeps the options it excludes off', function () {
    captureNotices();

    $resolved = dependencyResolver(acceptanceOptionsFor('POSTNL'), [ShipmentOption::RECEIPT_CODE => OptionSource::CONFIGURATION], 250)
        ->resolve()->toArray();

    expect($resolved[ShipmentOption::RECEIPT_CODE])->toBeTrue()
        ->and($resolved[ShipmentOption::INSURANCE])->toBe(250)
        ->and($resolved[ShipmentOption::SIGNATURE])->toBeFalse()
        ->and($resolved[ShipmentOption::ONLY_RECIPIENT])->toBeFalse();
});

it('adds no companion that an option already on excludes', function () {
    captureNotices();

    // Configured insurance requires signature, but receipt code excludes it: a valid PostNL shipment.
    $resolved = dependencyResolver(
        acceptanceOptionsFor('POSTNL'),
        [ShipmentOption::RECEIPT_CODE => OptionSource::CONFIGURATION],
        0,
        100
    )->resolve()->toArray();

    expect($resolved[ShipmentOption::INSURANCE])->toBe(100)
        ->and($resolved[ShipmentOption::SIGNATURE])->toBeFalse()
        ->and($resolved[ShipmentOption::ONLY_RECIPIENT])->toBeFalse();
});

it('settles a mutual exclusion by where each value came from', function (int $ageCheck, int $receiptCode, string $loser) {
    $notices = captureNotices();

    $resolved = dependencyResolver(acceptanceOptionsFor('POSTNL'), [
        ShipmentOption::AGE_CHECK    => $ageCheck,
        ShipmentOption::RECEIPT_CODE => $receiptCode,
    ], 250)->resolve()->toArray();

    $winner = ShipmentOption::AGE_CHECK === $loser ? ShipmentOption::RECEIPT_CODE : ShipmentOption::AGE_CHECK;

    expect($resolved[$loser])->toBeFalse()
        ->and($resolved[$winner])->toBeTrue()
        ->and($notices->getArrayCopy())->toContain(sprintf(
            'Shipment option %s left off order 100000001: it excludes %s, which was decided with more weight.',
            $loser,
            $winner
        ));
})->with([
    '18+ product beats a configured receipt code'  => [OptionSource::PRODUCT, OptionSource::CONFIGURATION, ShipmentOption::RECEIPT_CODE],
    'customer choice beats a configured age check' => [OptionSource::CONFIGURATION, OptionSource::CHECKOUT, ShipmentOption::AGE_CHECK],
    'merchant choice beats a customer age check'   => [OptionSource::CHECKOUT, OptionSource::MERCHANT, ShipmentOption::AGE_CHECK],
    '18+ product beats a merchant receipt code'    => [OptionSource::PRODUCT, OptionSource::MERCHANT, ShipmentOption::RECEIPT_CODE],
]);

it('settles exclusions before requires, so a dropped option adds no companion', function () {
    captureNotices();

    $resolved = dependencyResolver(acceptanceOptionsFor('POSTNL'), [
        ShipmentOption::AGE_CHECK    => OptionSource::PRODUCT,
        ShipmentOption::RECEIPT_CODE => OptionSource::CONFIGURATION,
    ], 250)->resolve()->toArray();

    expect($resolved[ShipmentOption::RECEIPT_CODE])->toBeFalse()
        ->and($resolved[ShipmentOption::INSURANCE])->toBe(0)
        ->and($resolved[ShipmentOption::SIGNATURE])->toBeTrue();
});

it('keeps both options of an equal tier and leaves the refusal to the API', function () {
    captureNotices();

    $resolved = dependencyResolver(
        acceptanceOptionsFor('POSTNL'),
        [ShipmentOption::AGE_CHECK => OptionSource::CHECKOUT, ShipmentOption::RECEIPT_CODE => OptionSource::CHECKOUT],
        250
    )->resolve()->toArray();

    expect($resolved[ShipmentOption::AGE_CHECK])->toBeTrue()
        ->and($resolved[ShipmentOption::RECEIPT_CODE])->toBeTrue();
});

it('changes nothing when the capabilities cannot be read', function () {
    captureNotices();

    $resolved = dependencyResolver(null, [ShipmentOption::AGE_CHECK => OptionSource::CHECKOUT, ShipmentOption::RECEIPT_CODE => OptionSource::CHECKOUT])
        ->resolve()->toArray();

    expect($resolved[ShipmentOption::AGE_CHECK])->toBeTrue()
        ->and($resolved[ShipmentOption::RECEIPT_CODE])->toBeTrue()
        ->and($resolved[ShipmentOption::SIGNATURE])->toBeFalse();
});

it('insures a required companion at the contract minimum when nothing is configured', function () {
    captureNotices();

    $graph = acceptanceOptionsFor('POSTNL', ['insurance' => ['min' => ['amount' => 10000]]]);

    $resolved = dependencyResolver($graph, [ShipmentOption::RECEIPT_CODE => OptionSource::CONFIGURATION])
        ->resolve()->toArray();

    expect($resolved[ShipmentOption::INSURANCE])->toBe(100);
});

it('leaves insurance off when no amount can be found, and says so', function () {
    $notices = captureNotices();

    $resolved = dependencyResolver(acceptanceOptionsFor('POSTNL'), [ShipmentOption::RECEIPT_CODE => OptionSource::CONFIGURATION])
        ->resolve()->toArray();

    expect($resolved[ShipmentOption::INSURANCE])->toBe(0)
        ->and($notices->getArrayCopy())->toContain(
            'Order 100000001: receipt_code requires insurance, but no insurance amount is configured and the contract sets no minimum.'
        );
});

it('resolves an option only capabilities offer, so it reaches the export', function () {
    captureNotices();

    $resolved = dependencyResolver(
        acceptanceOptionsFor('POSTNL', ['noTracking' => ['requires' => [], 'excludes' => []]]),
        ['no_tracking' => OptionSource::CONFIGURATION]
    )->resolve();

    expect($resolved->discovered())->toBe(['no_tracking' => true]);
});
