<?php

declare(strict_types=1);

use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\OrderGridColumns;
use MyParcelNL\Magento\Service\ShipmentOptions\OptionChanges;
use MyParcelNL\Magento\Service\ShipmentOptions\OrderOptionsWriter;

/**
 * A merchant's change lands in the order's stored delivery options, and only the fields it names.
 *
 * @param array<int,\Magento\Sales\Model\Order> $orders
 * @param array<int,string|null>                $apiKeyByStore
 *
 * @return object{saved: array<int,array<string,mixed>>}
 */
function writeOrderOptions(array $orders, array $changes, ?CapabilitySet $capabilities = null, array $apiKeyByStore = [1 => 'key-a']): object
{
    $did = new class { public array $saved = []; };

    $repository = Mockery::mock(CapabilitiesRepository::class);
    $repository->shouldReceive('forStore')->andReturn($capabilities ?? CapabilitySet::fromApiResults([capabilityResult()]));

    $apiProvider = Mockery::mock(ShipmentApiProvider::class);
    $apiProvider->shouldReceive('apiKeyForStoreOrNull')->andReturnUsing(
        static fn(?int $storeId): ?string => $apiKeyByStore[$storeId] ?? null
    );

    $gridColumns = Mockery::mock(OrderGridColumns::class);
    $gridColumns->shouldReceive('update')->andReturnUsing(static function (int $orderId, array $columns) use ($did): void {
        $did->saved[$orderId] = $columns;
    });

    (new OrderOptionsWriter($repository, $apiProvider, $gridColumns))
        ->write($orders, OptionChanges::fromRequest($changes));

    return $did;
}

/** An order double that records what the writer sets on it. */
function writableOrder(array $stored, array $overrides = []): \Magento\Sales\Model\Order
{
    $order = createOrder(array_merge([
        'deliveryOptions'    => json_encode($stored),
        'getShippingAddress' => createAddress(),
    ], $overrides));
    $order->written = [];
    $order->shouldReceive('setData')->andReturnUsing(static function (string $key, $value) use ($order) {
        $order->written[$key] = $value;

        return $order;
    });

    return $order;
}

function writtenOptions(object $did, int $orderId = 1): array
{
    return json_decode($did->saved[$orderId][Config::FIELD_DELIVERY_OPTIONS], true);
}

it('writes only the options the merchant changed, and keeps the rest inheriting', function () {
    $order = writableOrder([
        'carrier'         => 'postnl',
        'deliveryType'    => 'standard',
        'shipmentOptions' => ['onlyRecipient' => true, 'signature' => null],
    ]);

    $did     = writeOrderOptions([$order], ['options' => ['signature' => '0']]);
    $options = writtenOptions($did)['shipmentOptions'];

    expect($options['signature'])->toBeFalse()
        ->and($options['only_recipient'])->toBeTrue()
        ->and($options['age_check'])->toBeNull()
        ->and($did->saved[1])->not->toHaveKey(Config::FIELD_MYPARCEL_CARRIER);
});

it('sets the written columns on the order too, so a summary built after the save shows them', function () {
    $order = writableOrder(['carrier' => 'postnl', 'deliveryType' => 'standard']);
    $did   = writeOrderOptions([$order], ['options' => ['signature' => '1']]);

    expect($order->written)->toBe($did->saved[1]);
});

it('writes package type, insurance, label amount and digital stamp weight', function () {
    $did = writeOrderOptions(
        [writableOrder(['carrier' => 'postnl', 'deliveryType' => 'standard'])],
        ['package_type' => 'mailbox', 'insurance' => '500', 'label_amount' => '2', 'digital_stamp_weight' => '50']
    );

    $stored = writtenOptions($did);

    expect($stored['packageType'])->toBe('mailbox')
        ->and($stored['shipmentOptions']['insurance'])->toBe(500)
        ->and($stored['labelAmount'])->toBe(2)
        ->and($stored['digitalStampWeight'])->toBe(50);
});

it('resets a pickup to home delivery when the carrier changes, and syncs the carrier column', function () {
    $order = writableOrder([
        'carrier'        => 'postnl',
        'deliveryType'   => 'pickup',
        'isPickup'       => true,
        'pickupLocation' => ['locationCode' => '216877', 'postalCode' => '2132BA'],
    ]);

    $capabilities = CapabilitySet::fromApiResults([capabilityResult(), capabilityResult(['carrier' => 'DHL_FOR_YOU'])]);
    $did          = writeOrderOptions([$order], ['carrier' => 'dhlforyou'], $capabilities);
    $stored       = writtenOptions($did);

    expect($stored['carrier'])->toBe('dhlforyou')
        ->and($stored['deliveryType'])->toBe('standard')
        ->and($stored['isPickup'])->toBeFalse()
        ->and($stored['pickupLocation'])->toBeNull()
        ->and($did->saved[1][Config::FIELD_MYPARCEL_CARRIER])->toBe('dhlforyou');
});

it('refuses an option the account does not offer for the shape, and writes nothing', function () {
    $order = writableOrder(['carrier' => 'postnl', 'deliveryType' => 'standard']);

    expect(fn() => writeOrderOptions([$order], ['options' => ['age_check' => '1']]))
        ->toThrow(LocalizedException::class, 'age_check');
});

it('refuses a carrier the account has no contract for', function () {
    $order = writableOrder(['carrier' => 'postnl', 'deliveryType' => 'standard']);

    expect(fn() => writeOrderOptions([$order], ['carrier' => 'dpd']))
        ->toThrow(LocalizedException::class, 'dpd');
});

it('accepts anything while the capabilities are unverified', function () {
    $did = writeOrderOptions(
        [writableOrder(['carrier' => 'postnl', 'deliveryType' => 'standard'])],
        ['options' => ['age_check' => '1']],
        CapabilitySet::permissive()
    );

    expect(writtenOptions($did)['shipmentOptions']['age_check'])->toBeTrue();
});

it('refuses a selection that spans two MyParcel accounts', function () {
    $orders = [
        writableOrder(['deliveryType' => 'standard'], ['getId' => 1, 'getStoreId' => 1]),
        writableOrder(['deliveryType' => 'standard'], ['getId' => 2, 'getStoreId' => 2]),
    ];

    expect(fn() => writeOrderOptions($orders, ['options' => ['signature' => '1']], null, [1 => 'key-a', 2 => 'key-b']))
        ->toThrow(LocalizedException::class, 'one MyParcel account');
});

it('gives an order without stored delivery options a standard delivery to change', function () {
    // No stored carrier, so the check asks DefaultOptions for the store's default one.
    $config = createConfig();
    $config->shouldReceive('getDefaultCarrierName')->andReturn('postnl');
    mockLoggerFacade([Config::class => $config]);

    $did = writeOrderOptions([writableOrder([])], ['options' => ['signature' => '1']]);

    expect(writtenOptions($did)['deliveryType'])->toBe('standard')
        ->and(writtenOptions($did)['shipmentOptions']['signature'])->toBeTrue();
});

it('ignores an option name that is not a plain snake_case key', function () {
    expect(OptionChanges::fromRequest(['options' => ['sig<x>' => '1', 'signature' => '1']])->options())
        ->toBe(['signature' => true]);
});

it('ignores a label amount outside one to the maximum', function ($amount, $expected) {
    expect(OptionChanges::fromRequest(['label_amount' => $amount])->labelAmount())->toBe($expected);
})->with([
    'one'          => ['1', 1],
    'the maximum'  => [(string) OptionChanges::MAX_LABEL_AMOUNT, OptionChanges::MAX_LABEL_AMOUNT],
    'zero'         => ['0', null],
    'over the max' => ['1000000', null],
]);

it('changes the delivery date and the drop-off day with it', function () {
    $monday = date('Y-m-d', strtotime('next monday', strtotime('+1 day')));
    $did    = writeOrderOptions(
        [writableOrder(['carrier' => 'postnl', 'deliveryType' => 'standard', 'date' => '2020-01-01 00:00:00'])],
        ['delivery_date' => $monday]
    );

    expect(writtenOptions($did)['date'])->toBe($monday . ' 00:00:00')
        ->and($did->saved[1][Config::FIELD_DROP_OFF_DAY])->toBe(date('Y-m-d', strtotime($monday . ' -2 days')));
});

it('removes the delivery date and clears the drop-off day', function () {
    $did = writeOrderOptions(
        [writableOrder(['carrier' => 'postnl', 'deliveryType' => 'standard', 'date' => '2099-01-01 00:00:00'])],
        ['delivery_date' => 'none']
    );

    expect(writtenOptions($did)['date'])->toBeNull()
        ->and($did->saved[1])->toHaveKey(Config::FIELD_DROP_OFF_DAY)
        ->and($did->saved[1][Config::FIELD_DROP_OFF_DAY])->toBeNull();
});

it('refuses a delivery date before tomorrow, and ignores one that is not a date', function () {
    $order = writableOrder(['carrier' => 'postnl', 'deliveryType' => 'standard']);

    expect(fn() => writeOrderOptions([$order], ['delivery_date' => date('Y-m-d')]))
        ->toThrow(LocalizedException::class, 'tomorrow')
        ->and(OptionChanges::fromRequest(['delivery_date' => '2099-02-30'])->isEmpty())->toBeTrue();
});

it('sets and removes dimensions, and drops the key when none is left', function () {
    $set = writeOrderOptions(
        [writableOrder(['carrier' => 'postnl', 'deliveryType' => 'standard'])],
        ['length' => '40', 'width' => '30', 'height' => '20']
    );
    $removed = writeOrderOptions(
        [writableOrder(['carrier' => 'postnl', 'deliveryType' => 'standard', 'physicalProperties' => ['length' => 40]])],
        ['length' => '0']
    );

    expect(writtenOptions($set)['physicalProperties'])->toBe(['length' => 40, 'width' => 30, 'height' => 20])
        ->and(writtenOptions($removed))->not->toHaveKey('physicalProperties');
});
