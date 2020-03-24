<?php

namespace MyParcelBE\Magento\Model\Checkout;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Quote\Model\QuoteRepository;

class ShippingInformationManagementPlugin
{

    protected $quoteRepository;

    public function __construct(
        QuoteRepository $quoteRepository
    ) {
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * @param \Magento\Checkout\Model\ShippingInformationManagement   $subject
     * @param                                                         $cartId
     * @param \Magento\Checkout\Api\Data\ShippingInformationInterface $addressInformation
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagement $subject,
        $cartId,
        ShippingInformationInterface $addressInformation
    ) {
        $extAttributes = $addressInformation->getExtensionAttributes();
        // @todo check delivery options from field (step 1)
        if (! empty($extAttributes) &&
            ! empty($extAttributes->getMyparcelDeliveryOptions()) &&
            $extAttributes->getMyparcelDeliveryOptions() != '{}'
        ) {

            $deliveryOptions = $extAttributes->getMyparcelDeliveryOptions();
            $quote = $this->quoteRepository->getActive($cartId);
            $quote->setMyparcelDeliveryOptions($deliveryOptions);
        }
    }
}
