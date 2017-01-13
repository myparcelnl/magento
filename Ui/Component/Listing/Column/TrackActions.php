<?php
/**
 * Show the actions of the track
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2016 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcel\Magento\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\UrlInterface;

/**
 * Class DepartmentActions
 */
class TrackActions extends Column
{
    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @param ContextInterface   $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface       $urlBuilder
     * @param array              $components
     * @param array              $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    )
    {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     *
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                if($item['track_status'] == null) {
                    $item[$this->getData('name')]['action-ship_direct'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'adminhtml/order_shipment/start',
                            [
                                'order_id' => $item['entity_id']
                            ]
                        ),
                        'label' => __('Ship'),
                        'hidden' => false,
                    ];
                }
                $item[$this->getData('name')]['action-download_package_label'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'myparcel/order/MassTrackTraceLabel',
                        [
                            'selected_ids' => $item['entity_id'],
                            'mypa_package_type' => 1,
                            'mypa_request_type' => 'download'
                        ]
                    ),
                    'label' => __('Download package label'),
                    'hidden' => false,
                ];
                $item[$this->getData('name')]['action-download_mailbox_label'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'myparcel/order/MassTrackTraceLabel',
                        [
                            'selected_ids' => $item['entity_id'],
                            'mypa_package_type' => 2,
                            'mypa_request_type' => 'download'
                        ]
                    ),
                    'label' => __('Download mailbox label'),
                    'hidden' => false,
                ];
                $item[$this->getData('name')]['action-download_letter_label'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'myparcel/order/MassTrackTraceLabel',
                        [
                            'selected_ids' => $item['entity_id'],
                            'mypa_package_type' => 3,
                            'mypa_request_type' => 'download'
                        ]
                    ),
                    'label' => __('Download letter label'),
                    'hidden' => false,
                ];
                $item[$this->getData('name')]['action-create_concept'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'myparcel/order/MassTrackTraceLabel',
                        [
                            'selected_ids' => $item['entity_id'],
                            'mypa_request_type' => 'concept'
                        ]
                    ),
                    'label' => __('Create concept'),
                    'hidden' => false,
                ];
                if ($item['track_number'] !== null) {
                    foreach (explode(PHP_EOL, $item['track_number']) as $trackNumber) {

                        $url = 'https://mijnpakket.postnl.nl/Inbox/Search?&b=' . $trackNumber . '&p=2231JE';
                        $item[$this->getData('name')]['action-track-' . $trackNumber] = [
                            'href' => $url,
                            'label' => $trackNumber,
                            'hidden' => false,
                        ];
                    }
                }

            }
        }

        return $dataSource;
    }
}