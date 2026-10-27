<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ShipmentOptions;

use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Sdk\Helper\SplitStreet;
use RuntimeException;

/**
 * The query fields `/delivery_options` and `/pickup_locations` take for an order's shipping address.
 *
 * The street is split the way the export splits it, against the home country of the store's account.
 */
class ShippingAddressQuery
{
    private StoredAccount $storedAccount;

    public function __construct(StoredAccount $storedAccount)
    {
        $this->storedAccount = $storedAccount;
    }

    /**
     * @return array{cc:string,postal_code:string,number:string,city:string,street:string,platform:string}
     * @throws RuntimeException when the order has no shipping address
     */
    public function forOrder(Order $order): array
    {
        $address = $order->getShippingAddress();

        if (null === $address) {
            throw new RuntimeException('the order has no shipping address');
        }

        $storeId = (int) $order->getStoreId();
        $cc      = (string) $address->getCountryId();
        $street  = SplitStreet::splitStreet(
            implode(' ', $address->getStreet() ?? []),
            $this->storedAccount->homeCountryForStore($storeId),
            $cc
        );

        return [
            'cc'          => $cc,
            'postal_code' => (string) preg_replace('/\s+/', '', (string) $address->getPostcode()),
            'number'      => (string) $street->getNumber(),
            'city'        => (string) $address->getCity(),
            'street'      => $street->getStreet(),
            'platform'    => ($this->storedAccount->propositionForStore($storeId) ?? Proposition::default())->getName(),
        ];
    }
}
