<?php

namespace MyParcelNL\Magento\Ui\Component\Listing\Column;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Ui\Component\Listing\Columns\Column;
use MyParcelNL\Sdk\src\Helper\TrackTraceUrl;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class TrackAndTrace extends Column
{
    public const NAME        = 'track_number';
    public const VALUE_EMPTY = '–';
    private const KEY_POSTCODE    = 0;

    /**
     * Script tag to unbind the click event from the td wrapping the barcode link.
     */
    private const SCRIPT_UNBIND_CLICK = "<script type='text/javascript'>jQuery('.myparcel-barcode-link').closest('td').unbind('click');</script>";

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

            $postalCode = array_slice($addressParts, -1)[self::KEY_POSTCODE];

            // Stop if either the barcode or postal code is missing.
            if (! $item['track_number'] || $item['track_number'] === self::VALUE_EMPTY || ! $postalCode) {
                continue;
            }

            $trackNumber = $item['track_number'];
            $countryId   = $this->getCountryWithEntityId($item);
            $data        = $this->getData('name');

            // Render the T&T as a link and add the script to remove the click handler.
            $trackTrace  = (new TrackTraceUrl())->create($trackNumber, $postalCode, $countryId);
            $item[$data] = "<a class=\"myparcel-barcode-link\" target=\"_blank\" href=\"$trackTrace\">$trackNumber</a>";
            $item[$data] .= self::SCRIPT_UNBIND_CLICK;
        }

        return $dataSource;
    }

    /**
     * @param array $orderData
     *
     * @return mixed
     */
    public function getCountryWithEntityId(array $orderData): string
    {
        $order     = (ObjectManager::getInstance())->create(Order::class)->load($orderData['entity_id']);
        $countryId = $order->getShippingAddress()->getCountryId();

        if (! $countryId) {
            $countryId = AbstractConsignment::CC_NL;
        }

        return $countryId;
    }
}
