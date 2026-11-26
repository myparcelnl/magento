<?php

declare(strict_types=1);

use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\ShipmentOptions\ShippingAddressQuery;

/** A dated entry as DeliveryDates::forOrder() returns it. */
function datedDelivery(string $date, string $deliveryType, ?string $start = null, ?string $end = null): array
{
    return ['date' => $date, 'deliveryType' => $deliveryType, 'start' => $start, 'end' => $end];
}

/** A location as PickupLocations::near() returns it. */
function foundPickupLocation(string $code = '176227', ?string $date = '2099-10-29'): array
{
    return [
        'location_code'     => $code,
        'location_name'     => 'Primera',
        'retail_network_id' => 'PNPNL-01',
        'street'            => 'Antareslaan',
        'number'            => '31',
        'postal_code'       => '2132JE',
        'city'              => 'Hoofddorp',
        'cc'                => 'NL',
        'distance'          => '350',
        'date'              => $date,
    ];
}

/** @return string[] every entry value of the list, in order */
function deliveryValues(array $list): array
{
    $values = [];

    foreach ($list['groups'] as $group) {
        foreach ($group['entries'] as $entry) {
            $values[] = $entry['value'];
        }
    }

    return $values;
}

function selectedDelivery(array $list): ?string
{
    foreach ($list['groups'] as $group) {
        foreach ($group['entries'] as $entry) {
            if ($entry['selected']) {
                return $entry['value'];
            }
        }
    }

    return null;
}

/** An order shipped to an NL address, with these shipment options stored on it. */
function orderShippedTo(array $address = [], array $shipmentOptions = []): Order
{
    return createOrder([
        'getShippingAddress' => createAddress($address),
        'getAllVisibleItems' => [],
        'deliveryOptions'    => json_encode(['carrier' => 'postnl', 'deliveryType' => 'standard', 'shipmentOptions' => $shipmentOptions]),
    ]);
}

/** A provider that hands every store this SDK client. */
function shipmentApiProviderWith($client): ShipmentApiProvider
{
    $apiProvider = Mockery::mock(ShipmentApiProvider::class);
    $apiProvider->shouldReceive('apiKeyForStore')->andReturn('test-key');
    $apiProvider->shouldReceive('clientFor')->andReturn($client);

    return $apiProvider;
}

/** An address query for an account at home in NL, on the default proposition. */
function nlShippingAddressQuery(): ShippingAddressQuery
{
    $storedAccount = Mockery::mock(StoredAccount::class);
    $storedAccount->shouldReceive('homeCountryForStore')->andReturn('NL');
    $storedAccount->shouldReceive('propositionForStore')->andReturn(null);

    return new ShippingAddressQuery($storedAccount);
}

/**
 * The answer of one shipment options controller, built without its constructor, for this many
 * orders. $properties are set on it as collaborators.
 *
 * @param class-string<\MyParcelNL\Magento\Controller\Adminhtml\ShipmentOptions\AbstractShipmentOptions> $class
 * @param array<string,mixed> $params the request parameters
 * @param array<string,mixed> $properties
 */
function shipmentOptionsAnswer(string $class, array $params, array $properties, int $orderCount = 1): array
{
    $controller = newInstanceWithoutConstructor($class);
    setPrivateProperty($controller, '_request', mockRequestWithParams($params));

    foreach ($properties as $name => $value) {
        setPrivateProperty($controller, $name, $value);
    }

    return invokePrivateMethod($controller, 'answer', [array_fill(0, $orderCount, createOrder())]);
}
