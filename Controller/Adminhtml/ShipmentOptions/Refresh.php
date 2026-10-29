<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\ShipmentOptions;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Block\Template;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Service\ShipmentOptions\DeliveryDates;
use MyParcelNL\Magento\Service\ShipmentOptions\DeliveryList;
use MyParcelNL\Magento\Service\ShipmentOptions\OrderOptionsWriter;
use MyParcelNL\Magento\ViewModel\ShipmentOptionsForm;
use MyParcelNL\Magento\ViewModel\ShipmentOptionsFormFactory;
use RuntimeException;

/**
 * What the options modal shows for one order's current selection: the delivery types capabilities
 * allow with the ticked options, the option block of the carrier and package type, and with
 * `with_dates` the delivery list from /delivery_options.
 *
 * The dates are asked only on open and on a carrier or package type change: a checkbox click
 * changes what capabilities allow, not what /delivery_options offers.
 */
class Refresh extends AbstractShipmentOptions
{
    private DeliveryDates $deliveryDates;

    public function __construct(
        Context                    $context,
        OrderOptionsWriter         $writer,
        ShipmentOptionsFormFactory $formFactory,
        OrderCollectionFactory     $orderCollectionFactory,
        ShipmentCollectionFactory  $shipmentCollectionFactory,
        DeliveryDates              $deliveryDates
    ) {
        parent::__construct($context, $writer, $formFactory, $orderCollectionFactory, $shipmentCollectionFactory);

        $this->deliveryDates = $deliveryDates;
    }

    protected function answer(array $orders): array
    {
        if (1 !== count($orders)) {
            throw new LocalizedException(__('Change the delivery of one order at a time.'));
        }

        $request      = $this->getRequest();
        $carrier      = (string) $request->getParam('carrier');
        $packageType  = (string) $request->getParam('package_type');
        $deliveryType = (string) ($request->getParam('delivery_type') ?: DeliveryType::STANDARD_NAME);
        $ticked       = array_values(array_filter((array) $request->getParam('options', []), [ShipmentOption::class, 'isOptionName']));
        $form         = $this->formFactory->create(['order' => $orders[0]]);
        $allowed      = $form->allowedDeliveryTypes($carrier, $packageType, $ticked);
        $deliveries   = null;

        if ('1' === (string) $request->getParam('with_dates')) {
            $deliveries = $this->deliveries($form, $orders[0], $carrier, $packageType, $allowed);
            // The modal posts the stored type before its list exists; the list may preselect another one.
            $deliveryType = DeliveryList::selectedType($deliveries) ?? $deliveryType;
        }

        $states = $form->optionStates($carrier, $packageType, $deliveryType, $ticked);
        $answer = [
            'allowedDeliveryTypes' => $allowed,
            'optionsHtml'          => [] === $states ? '' : $this->optionsHtml($form, $carrier, $packageType, $states),
            'emptyAnswer'          => $form->emptyAnswer(),
        ];

        if (null !== $deliveries) {
            $answer['deliveries'] = $deliveries;
        }

        return $answer;
    }

    /** @param string[] $allowedDeliveryTypes */
    private function deliveries(ShipmentOptionsForm $form, $order, string $carrier, string $packageType, array $allowedDeliveryTypes): array
    {
        try {
            $dated  = $this->deliveryDates->forOrder($order, $carrier, $packageType);
            $failed = false;
        } catch (RuntimeException $e) {
            Logger::notice('MyParcel delivery options for the options modal could not be read', LogContext::of($e));
            $dated  = [];
            $failed = true;
        }

        return DeliveryList::build($dated, $allowedDeliveryTypes, $form->storedDelivery(), $carrier, $failed);
    }

    /** @param array<string,array{checked:bool,disabled:bool,greyed:bool}> $states */
    protected function optionsHtml(ShipmentOptionsForm $form, string $carrier, string $packageType, array $states): string
    {
        return $this->_view->getLayout()
            ->createBlock(Template::class)
            ->setTemplate('MyParcelNL_Magento::shipment_options/options.phtml')
            ->setData('options_form', $form)
            ->setData('carrier', $carrier)
            ->setData('package_type', $packageType)
            ->setData('states', $states)
            ->setData('initial', $form->tickedOptions($carrier, $packageType))
            ->setData('empty_answer', $form->emptyAnswer())
            ->toHtml();
    }
}
