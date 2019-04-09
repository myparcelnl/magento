<?php
/**
 * Show the status of the track
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Ui\Component\Listing\Column;

use \Magento\Sales\Api\OrderRepositoryInterface;
use \Magento\Framework\View\Element\UiComponent\ContextInterface;
use \Magento\Framework\View\Element\UiComponentFactory;
use Magento\Sales\Model\Order;
use \Magento\Ui\Component\Listing\Columns\Column;
use \Magento\Framework\Api\SearchCriteriaBuilder;
use MyParcelNL\Sdk\src\Helper\TrackTraceUrl;

class TrackNumber extends Column
{
    /**
     * Set column MyParcel barcode to order grid
     *
     * @param array $dataSource
     * @param null $postcode
     *
     * @return array
     */
    public function prepareDataSource(array $dataSource, $postcode = null)
    {
        /**
         * @var Order $order
         * @var Order\Shipment\Track[] $tracks
         */
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {

                $address = explode(",", $item['shipping_address']);

                /* Get the postcode from address */
                if ( ! empty($address[2])) {
                    list($street, $city, $postcode) = explode(",", $item['shipping_address']);
                }

                if (key_exists('track_number', $item) && $postcode) {
                    $trackTrace = (new TrackTraceUrl())
                        ->create($item['track_number'], $postcode, null);

                    $item[$this->getData('name')] = '<a target="_blank" href=' . $trackTrace . ' >' . $item['track_number'] . '</a>';
                }
            }
        }

        return $dataSource;
    }
}
