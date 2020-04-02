<?php

namespace MyParcelNL\Magento\Ui\Component\Listing\Column;

use Magento\Sales\Model\Order;
use Magento\Ui\Component\Listing\Columns\Column;
use MyparcelNL\Sdk\src\Helper\TrackTraceUrl;

class TrackAndTrace extends Column
{
    const NAME = 'track_number';

    const VALUE_EMPTY = '–';

    /**
     * Script tag to unbind the click event from the td wrapping the barcode link.
     */
    const SCRIPT_UNBIND_CLICK = "<script type='text/javascript'>jQuery('.myparcel-barcode-link').closest('td').unbind('click');</script>";

    /**
     * Set column MyParcel barcode to order grid
     *
     * @param array $dataSource
     *
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        parent::prepareDataSource($dataSource);

        if (! isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        /**
         * @var Order                  $order
         * @var Order\Shipment\Track[] $tracks
         */
        foreach ($dataSource['data']['items'] as & $item) {
            $addressParts = explode(",", $item['shipping_address']);

            if (count($addressParts) < 3) {
                continue;
            }

            if (count($addressParts) === 4) {
                [$company, $street, $city, $postalCode] = $addressParts;
            } else {
                [$street, $city, $postalCode] = $addressParts;
            }

            // Stop if either the barcode or postal code is missing.
            if (! $item['track_number'] || $item['track_number'] === self::VALUE_EMPTY || ! $postalCode) {
                continue;
            }

            $trackNumber = $item['track_number'];
            $data = $this->getData('name');

            // Render the T&T as a link and add the script to remove the click handler.
            $trackTrace = (new TrackTraceUrl())->create($trackNumber, $postalCode);
            $item[$data] = "<a class=\"myparcel-barcode-link\" target=\"_blank\" href=\"$trackTrace\">$trackNumber</a>";
            $item[$data] .= self::SCRIPT_UNBIND_CLICK;
        }

        return $dataSource;
    }
}
