<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use MyParcelNL\Pdk\Facade\Pdk;
use Magento\Checkout\Model\Session;

class MagentoAddressAdapter
{
    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * @param  \Magento\Sales\Model\Order $order
     * @param  null|string                $addressType
     *
     * @return array
     */
    public function fromMagentoOrder(Order $order, ?string $addressType = null): array
    {
        $resolvedAddressType = $this->resolveAddressType($order, $addressType);
        $address             = Pdk::get('addressTypeShipping') === $resolvedAddressType
            ? $order->getShippingAddress()
            : $order->getBillingAddress();

        return $address ? $this->getAddressFields($address) : [];
    }

    public function fromMagentoQuote(string $addressType): array
    {
        $quote   = $this->objectManager->get(Session::class)
            ->getQuote();
        $address = Pdk::get('addressTypeShipping') === $addressType
            ? $quote->getShippingAddress()
            : $quote->getBillingAddress();

        return $address ? $this->getAddressFields($address) : [];
    }

    /**
     * @param  \Magento\Sales\Model\Order\Address $address
     *
     * @return array
     */
    private function getAddressFields(Order\Address $address): array
    {
        return [
            'email'      => $address->getEmail(),
            'phone'      => $address->getTelephone(),
            'person'     => $address->getName(),
            'address1'   => $address->getStreet(),
            'address2'   => $address->getStreet(),
            'cc'         => $address->getCountryId(),
            'city'       => $address->getCity(),
            'company'    => $address->getCompany(),
            'postalCode' => $address->getPostcode(),
            'region'     => $address->getRegion(),
            'state'      => $address->getRegion(),
        ];
    }

    /**
     * @param  \Magento\Sales\Model\Order $magentoOrder
     * @param  null|string                $addressType
     *
     * @return string
     */
    private function resolveAddressType(Order $magentoOrder, ?string $addressType): string
    {
        return $addressType ?? ($magentoOrder->getShippingAddress()
            ? Pdk::get('addressTypeShipping')
            : Pdk::get('addressTypeBilling'));
    }
}
