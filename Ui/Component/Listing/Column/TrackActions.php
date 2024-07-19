<?php

namespace MyParcelBE\Magento\Ui\Component\Listing\Column;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MyParcelBE\Magento\Helper\Data;
use MyParcelBE\Magento\Model\Sales\TrackTraceHolder;

/**
 * Class DepartmentActions
 */
class TrackActions extends Column
{
    const NAME = 'track_actions';

    /**
     * @var \MyParcelBE\Magento\Helper\Data
     */
    private $helper;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @param  ContextInterface                $context
     * @param  UiComponentFactory              $uiComponentFactory
     * @param  UrlInterface                    $urlBuilder
     * @param  \MyParcelBE\Magento\Helper\Data $helper
     * @param  array                           $components
     * @param  array                           $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        $objectManager    = ObjectManager::getInstance();
        $this->helper     = $objectManager->create(Data::class);
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

        $orderManagementActivated = TrackTraceHolder::EXPORT_MODE_PPS === $this->helper->getExportMode();

        foreach ($dataSource['data']['items'] as &$item) {
            if (! key_exists(ShippingStatus::NAME, $item)) {
                throw new LocalizedException(
                    __(
                        'Note that the installation of the extension was not successful. Some columns have not been added to the database. The installation should be reversed. Use the following command to reinstall the module: DELETE FROM `setup_module` WHERE `setup_module`.`module` = \'MyParcelNL_Magento\''
                    )
                );
                continue;
            }

            if (! isset($item[ShippingStatus::NAME])) {
                if (TrackTraceHolder::EXPORT_MODE_PPS === $this->helper->getExportMode()) {
                    $item[$this->getData('name')]['action-create_concept'] = [
                        'href'   => $this->urlBuilder->getUrl(
                            'myparcel/order/CreateAndPrintMyParcelTrack',
                            [
                                'selected_ids'      => $item['entity_id'],
                                'mypa_request_type' => 'concept',
                            ]
                        ),
                        'label'  => __('Export to MyParcel'),
                        'hidden' => ! $orderManagementActivated,
                    ];
                } else {
                    $item[$this->getData('name')]['action-download_package_label']       = [
                        'href'   => $this->urlBuilder->getUrl(
                            'myparcel/order/CreateAndPrintMyParcelTrack',
                            [
                                'selected_ids'      => $item['entity_id'],
                                'mypa_package_type' => 1,
                                'mypa_request_type' => 'download',
                            ]
                        ),
                        'label'  => __('Download package label'),
                        'hidden' => $orderManagementActivated,
                    ];
                    $item[$this->getData('name')]['action-download_digital_stamp_label'] = [
                        'href'   => $this->urlBuilder->getUrl(
                            'myparcel/order/CreateAndPrintMyParcelTrack',
                            [
                                'selected_ids'      => $item['entity_id'],
                                'mypa_package_type' => 4,
                                'mypa_request_type' => 'download',
                            ]
                        ),
                        'label'  => __('Download digital stamp label'),
                        'hidden' => $orderManagementActivated,
                    ];
                    $item[$this->getData('name')]['action-download_mailbox_label']       = [
                        'href'   => $this->urlBuilder->getUrl(
                            'myparcel/order/CreateAndPrintMyParcelTrack',
                            [
                                'selected_ids'      => $item['entity_id'],
                                'mypa_package_type' => 2,
                                'mypa_request_type' => 'download',
                            ]
                        ),
                        'label'  => __('Download mailbox label'),
                        'hidden' => $orderManagementActivated,
                    ];
                    $item[$this->getData('name')]['action-download_letter_label']        = [
                        'href'   => $this->urlBuilder->getUrl(
                            'myparcel/order/CreateAndPrintMyParcelTrack',
                            [
                                'selected_ids'      => $item['entity_id'],
                                'mypa_package_type' => 3,
                                'mypa_request_type' => 'download',
                            ]
                        ),
                        'label'  => __('Download letter label'),
                        'hidden' => $orderManagementActivated,
                    ];
                    $item[$this->getData('name')]['action-create_concept']               = [
                        'href'   => $this->urlBuilder->getUrl(
                            'myparcel/order/CreateAndPrintMyParcelTrack',
                            [
                                'selected_ids'      => $item['entity_id'],
                                'mypa_request_type' => 'concept',
                            ]
                        ),
                        'label'  => __('Create new concept'),
                        'hidden' => $orderManagementActivated,
                    ];
                    $item[$this->getData('name')]['action-ship_direct']                  = [
                        'href'   => $this->urlBuilder->getUrl(
                            'adminhtml/order_shipment/start',
                            [
                                'order_id' => $item['entity_id'],
                            ]
                        ),
                        'label'  => __('Create shipment'),
                        'hidden' => $orderManagementActivated,
                    ];
                }
            }

            if (isset($item[ShippingStatus::NAME])) {
                $item[$this->getData('name')]['action-create_concept']            = [
                    'href'   => $this->urlBuilder->getUrl(
                        'myparcel/order/CreateAndPrintMyParcelTrack',
                        [
                            'selected_ids'      => $item['entity_id'],
                            'mypa_request_type' => 'concept',
                        ]
                    ),
                    'label'  => __('Already exported'),
                    'hidden' => ! $orderManagementActivated,
                ];
                $item[$this->getData('name')]['action-download_package_label']    = [
                    'href'   => $this->urlBuilder->getUrl(
                        'myparcel/order/CreateAndPrintMyParcelTrack',
                        [
                            'selected_ids'      => $item['entity_id'],
                            'mypa_package_type' => 1,
                            'mypa_request_type' => 'download',
                        ]
                    ),
                    'label'  => __('Download label'),
                    'hidden' => $orderManagementActivated,
                ];
                $item[$this->getData('name')]['action-myparcel_send_return_mail'] = [
                    'href'   => $this->urlBuilder->getUrl(
                        'myparcel/order/SendMyParcelReturnMail',
                        [
                            'selected_ids' => $item['entity_id'],
                        ]
                    ),
                    'label'  => __('Send return label'),
                    'hidden' => $orderManagementActivated,
                ];
            }
        }

        return $dataSource;
    }
}
