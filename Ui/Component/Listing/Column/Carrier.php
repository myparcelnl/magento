<?php
/**
 * Show carrier column
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Richard Perdaan <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v3.0.0
 */

namespace MyParcelBE\Magento\Ui\Component\Listing\Column;

use Magento\Sales\Model\Order;
use Magento\Ui\Component\Listing\Columns\Column;

class Carrier extends Column
{
    /**
     * Set column MyParcel delivery date to order grid
     *
     * @param array $dataSource
     *
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        /**
         * @var Order                  $order
         * @var Order\Shipment\Track[] $tracks
         */
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                if (key_exists('myparcel_carrier', $item)) {
                    $item[$this->getData('name')] = $item['myparcel_carrier'];
                }
            }
        }

        return $dataSource;
    }
}
