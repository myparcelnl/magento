<?php

namespace MyParcelNL\Magento\Ui\Component\Listing\Column;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Export\LabelPositions;

/**
 * The order grid's per-row MyParcel actions: export as a package type, create a concept, download
 * the labels of an exported order, send a return label.
 *
 * None of the export actions is a plain link — the controllers answer JSON, so following the href
 * would put that JSON on screen. Each carries a callback into the grid's own JS instead.
 */
class TrackActions extends Column
{
    public const NAME = 'track_actions';

    private Config         $config;
    private UrlInterface   $urlBuilder;
    private LabelPositions $labelPositions;

    /**
     * @param ContextInterface   $context
     * @param Config             $config
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface       $urlBuilder
     * @param LabelPositions     $labelPositions
     * @param array              $components
     * @param array              $data
     */
    public function __construct(
        ContextInterface   $context,
        Config             $config,
        UiComponentFactory $uiComponentFactory,
        UrlInterface       $urlBuilder,
        LabelPositions     $labelPositions,
        array              $components = [],
        array              $data = []
    )
    {
        $this->urlBuilder     = $urlBuilder;
        $this->config         = $config;
        $this->labelPositions = $labelPositions;
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

        $orderManagementActivated = Config::EXPORT_MODE_PPS === $this->config->getExportMode();
        // Read once for the whole grid: the configured paper type is what a row action asks for,
        // since it renders no modal to pick one in.
        $positions = $this->labelPositions->encode($this->labelPositions->configured());

        foreach ($dataSource['data']['items'] as &$item) {
            if (! array_key_exists(ShippingStatus::NAME, $item)) {
                throw new LocalizedException(
                    __(
                        'Note that the installation of the extension was not successful. Some columns have not been added to the database. The installation should be reversed. Use the following command to reinstall the module: DELETE FROM `setup_module` WHERE `setup_module`.`module` = \'MyParcelNL_Magento\''
                    )
                );
            }

            $entityId = $item['entity_id'];
            $actions  = [];

            if (! isset($item[ShippingStatus::NAME])) {
                if ($orderManagementActivated) {
                    $actions['action-create_concept'] = $this->exportAction(
                        __('Export to MyParcel'),
                        $entityId,
                        ['mypa_request_type' => 'concept'],
                        ! $orderManagementActivated
                    );
                } else {
                    $downloads = [
                        'action-download_package_label'       => [__('Download package label'), 1],
                        'action-download_small_package_label' => [__('Download small package label'), 6],
                        'action-download_digital_stamp_label' => [__('Download digital stamp label'), 4],
                        'action-download_mailbox_label'       => [__('Download mailbox label'), 2],
                        'action-download_letter_label'        => [__('Download letter label'), 3],
                    ];

                    foreach ($downloads as $name => [$label, $packageType]) {
                        $actions[$name] = $this->exportAction(
                            $label,
                            $entityId,
                            ['mypa_package_type' => $packageType, 'mypa_request_type' => 'download'],
                            $orderManagementActivated
                        );
                    }

                    $actions['action-create_concept'] = $this->exportAction(
                        __('Create new concept'),
                        $entityId,
                        ['mypa_request_type' => 'concept'],
                        $orderManagementActivated
                    );

                    $actions['action-ship_direct'] = [
                        'href'   => $this->urlBuilder->getUrl('adminhtml/order_shipment/start', ['order_id' => $entityId]),
                        'label'  => __('Create shipment'),
                        'hidden' => $orderManagementActivated,
                    ];
                }
            } else {
                $actions['action-create_concept'] = $this->exportAction(
                    __('Already exported'),
                    $entityId,
                    ['mypa_request_type' => 'concept'],
                    ! $orderManagementActivated
                );

                // Straight to the labels: this order already shipped, so there is nothing to create.
                // Its own callback, not exportRow — the happy answer is a PDF stream, not JSON.
                $actions['action-download_package_label'] = [
                    'href'     => $this->urlBuilder->getUrl(
                        'myparcel/order/PrintMyParcelLabels',
                        ['order_ids' => $entityId, 'request_type' => 'download']
                        + ($positions ? ['positions' => $positions] : [])
                    ),
                    'label'    => __('Download label'),
                    'hidden'   => $orderManagementActivated,
                    'callback' => [
                        'provider' => 'myparcel_grid_massaction',
                        'target'   => 'downloadLabelRow',
                    ],
                ];

                $actions['action-myparcel_send_return_mail'] = [
                    'href'   => $this->urlBuilder->getUrl('myparcel/order/SendMyParcelReturnMail', ['selected_ids' => $entityId]),
                    'label'  => __('Send return label'),
                    'hidden' => $orderManagementActivated,
                ];
            }

            $item[$this->getData('name')] = ($item[$this->getData('name')] ?? []) + $actions;
        }

        return $dataSource;
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
