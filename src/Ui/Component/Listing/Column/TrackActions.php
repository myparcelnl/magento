<?php

namespace MyParcelNL\Magento\Ui\Component\Listing\Column;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;

/**
 * The order grid's per-row MyParcel actions: change the options, print the label, create a concept,
 * send a return label.
 *
 * None of the export actions is a plain link — the controllers answer JSON, so following the href
 * would put that JSON on screen. Each carries a callback into the grid's own JS instead.
 */
class TrackActions extends Column
{
    public const NAME = 'track_actions';

    private StoredAccount $storedAccount;
    private UrlInterface  $urlBuilder;

    /**
     * @param ContextInterface   $context
     * @param StoredAccount      $storedAccount
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface       $urlBuilder
     * @param array              $components
     * @param array              $data
     */
    public function __construct(
        ContextInterface   $context,
        StoredAccount      $storedAccount,
        UiComponentFactory $uiComponentFactory,
        UrlInterface       $urlBuilder,
        array              $components = [],
        array              $data = []
    )
    {
        $this->urlBuilder    = $urlBuilder;
        $this->storedAccount = $storedAccount;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Set MyParcel order grid actions
     *
     * @param array $dataSource
     *
     * @return array
     * @throws LocalizedException
     */
    public function prepareDataSource(array $dataSource)
    {
        if (! isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        // Read once for the whole grid: none of these vary per row, and a few hundred rows made a
        // Phrase of each.
        $printLabel       = __('Print label');
        $exportLabel      = __('Export to MyParcel');
        $newConceptLabel  = __('Create new concept');
        $shipmentLabel    = __('Create shipment');
        $alreadyExported  = __('Send to MyParcel again');
        $optionsAction    = [
            // The callback opens the options modal; the href is never followed.
            'href'     => '#',
            'label'    => __('Change MyParcel options'),
            'callback' => [
                'provider' => 'myparcel_grid_massaction',
                'target'   => 'openOptionsRow',
            ],
        ];

        foreach ($dataSource['data']['items'] as &$item) {
            if (! array_key_exists(ShippingStatus::NAME, $item)) {
                throw new LocalizedException(
                    __(
                        'Note that the installation of the extension was not successful. Some columns have not been added to the database. The installation should be reversed. Use the following command to reinstall the module: DELETE FROM `setup_module` WHERE `setup_module`.`module` = \'MyParcelNL_Magento\''
                    )
                );
            }

            $entityId = $item['entity_id'];
            $actions  = ['action-myparcel_options' => $optionsAction];

            // Per row: a grid holds orders of every store, and each store's account has its own mode.
            $orderV1 = $this->storedAccount->hasOrderV1ForStore(
                isset($item['store_id']) ? (int) $item['store_id'] : null
            );

            if (! isset($item[ShippingStatus::NAME])) {
                if ($orderV1) {
                    $actions['action-create_concept'] = $this->exportAction(
                        $exportLabel,
                        $entityId,
                        ['mypa_request_type' => 'concept'],
                        ! $orderV1
                    );
                } else {
                    // The order's own options decide the package type; the options modal changes them.
                    $actions['action-print_label'] = $this->printAction($printLabel, $entityId);

                    $actions['action-create_concept'] = $this->exportAction(
                        $newConceptLabel,
                        $entityId,
                        ['mypa_request_type' => 'concept'],
                        $orderV1
                    );

                    $actions['action-ship_direct'] = [
                        'href'   => $this->urlBuilder->getUrl('adminhtml/order_shipment/start', ['order_id' => $entityId]),
                        'label'  => $shipmentLabel,
                        'hidden' => $orderV1,
                    ];
                }
            } else {
                $actions['action-create_concept'] = $this->exportAction(
                    $alreadyExported,
                    $entityId,
                    ['mypa_request_type' => 'concept'],
                    ! $orderV1
                );

                if (! $orderV1) {
                    $actions['action-print_label'] = $this->printAction($printLabel, $entityId);
                }

                // The callback keeps this a POST. Without one the column navigates to the href,
                // which would create a return label and mail the consumer over a GET.
                $actions['action-myparcel_send_return_mail'] = [
                    'href'     => $this->urlBuilder->getUrl('myparcel/order/SendMyParcelReturnMail', ['selected_ids' => $entityId]),
                    'label'    => __('Send return label'),
                    'hidden'   => $orderV1,
                    'callback' => [
                        'provider' => 'myparcel_grid_massaction',
                        'target'   => 'exportRow',
                    ],
                ];
            }

            $item[$this->getData('name')] = ($item[$this->getData('name')] ?? []) + $actions;
        }

        return $dataSource;
    }

    /**
     * Prints the order's labels, creating its shipment and concept first when it has none: the same
     * export the order view's "Print label" runs, with the configured paper size.
     *
     * @param \Magento\Framework\Phrase $label
     * @param mixed                     $entityId
     */
    private function printAction($label, $entityId): array
    {
        return $this->exportAction($label, $entityId, ['mypa_request_type' => 'download'], false);
    }

    /**
     * One row export action: a link to the export controller plus the callback that makes the grid's
     * JS fetch it instead of navigating to its JSON answer.
     *
     * @param \Magento\Framework\Phrase $label
     * @param mixed                     $entityId
     */
    private function exportAction($label, $entityId, array $params, bool $hidden): array
    {
        return [
            'href'     => $this->urlBuilder->getUrl(
                'myparcel/order/CreateAndPrintMyParcelTrack',
                ['selected_ids' => $entityId] + $params
            ),
            'label'    => $label,
            'hidden'   => $hidden,
            'callback' => [
                'provider' => 'myparcel_grid_massaction',
                'target'   => 'exportRow',
            ],
        ];
    }
}
