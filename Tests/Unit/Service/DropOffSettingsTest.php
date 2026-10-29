<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DropOffSettings;

// configWithStoreRows() lives in Tests/Helpers/ConfigMocks.php.

const DROP_OFF_POSTNL = 'myparcelnl_magento_postnl_settings/';

it('lists the active drop-off days of the store with their cutoff times', function () {
    $settings = new DropOffSettings(configWithStoreRows([5 => [
        DROP_OFF_POSTNL . 'drop_off_days/day_1_active'           => '1',
        DROP_OFF_POSTNL . 'drop_off_days/cutoff_time_1'          => '17,00,00',
        DROP_OFF_POSTNL . 'drop_off_days/day_2_active'           => '1',
        DROP_OFF_POSTNL . 'drop_off_days/cutoff_time_2'          => '16,30,00',
        DROP_OFF_POSTNL . 'drop_off_days/cutoff_time_same_day_2' => '10,00,00',
        DROP_OFF_POSTNL . 'drop_off_days/day_3_active'           => '0',
    ]]));

    expect($settings->dropOffDays(DROP_OFF_POSTNL, 5))->toEqual([
        (object) ['weekday' => 1, 'cutoffTime' => '17:00'],
        (object) ['weekday' => 2, 'cutoffTime' => '16:30', 'cutoffTimeSameDay' => '10:00'],
    ]);
});

it('reads the delivery days window and the drop-off delay of the store', function () {
    $settings = new DropOffSettings(configWithStoreRows([5 => [
        Config::XML_PATH_GENERAL . 'date_settings/deliverydays_window' => '3',
        Config::XML_PATH_GENERAL . 'date_settings/dropoff_delay'       => '2',
    ]]));

    expect($settings->deliveryDaysWindow(5))->toBe(3)
        ->and($settings->dropOffDelay(5))->toBe(2);
});

it('reads the current store when no store is given', function () {
    $settings = new DropOffSettings(configWithStoreRows([1 => [
        Config::XML_PATH_GENERAL . 'date_settings/deliverydays_window' => '4',
    ]]));

    expect($settings->deliveryDaysWindow())->toBe(4);
});
