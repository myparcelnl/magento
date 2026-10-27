<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\ShipmentOptions;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Service\ShipmentOptions\OrderOptionsWriter;
use MyParcelNL\Magento\Service\ShipmentOptions\PickupLocations as PickupLocationsService;
use MyParcelNL\Magento\ViewModel\ShipmentOptionsFormFactory;
use RuntimeException;

/** The pickup locations near one order's shipping address, for the carrier the modal shows. */
class PickupLocations extends AbstractShipmentOptions
{
    private PickupLocationsService $pickupLocations;

    public function __construct(
        Context                    $context,
        OrderOptionsWriter         $writer,
        ShipmentOptionsFormFactory $formFactory,
        OrderCollectionFactory     $orderCollectionFactory,
        ShipmentCollectionFactory  $shipmentCollectionFactory,
        PickupLocationsService     $pickupLocations
    ) {
        parent::__construct($context, $writer, $formFactory, $orderCollectionFactory, $shipmentCollectionFactory);

        $this->pickupLocations = $pickupLocations;
    }

    protected function answer(array $orders): array
    {
        if (1 !== count($orders)) {
            throw new LocalizedException(__('Change the delivery of one order at a time.'));
        }

        try {
            return ['locations' => $this->pickupLocations->near($orders[0], (string) $this->getRequest()->getParam('carrier'))];
        } catch (RuntimeException $e) {
            Logger::notice('MyParcel pickup locations for the options modal could not be read', LogContext::of($e));

            throw new LocalizedException(__('MyParcel could not load the pickup locations. Try again.'));
        }
    }
}
