<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Ui\Component\Listing\Column;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Ui\Component\Listing\Columns\Column;
use MyParcelNL\Sdk\src\Helper\TrackTraceUrl;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class TrackAndTrace extends Column
{
    public const  NAME          = 'track_number';
    public const  VALUE_EMPTY   = '–';
    public const  VALUE_PRINTED = 'printed';
    public const  VALUE_CONCEPT = 'concept';
    private const KEY_POSTCODE  = 0;

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
            $addressParts = explode(',', $item['shipping_address'] ?? '');

            if (count($addressParts) < 3) {
                continue;
            }

            $postalCode = array_slice($addressParts, -1)[self::KEY_POSTCODE];

            // Stop if either the barcode or postal code is missing.
            if (! $item['track_number'] || ! $postalCode) {
                continue;
            }

            $order = $this->getOrderByEntityId((int) $item['entity_id']);
            $name  = $this->getData('name');

            // Render the T&T as a link and add the script to remove the click handler.
            $item[$name] = self::getTrackAndTraceLinksAsHtml($order);
            $item[$name] .= self::SCRIPT_UNBIND_CLICK;
        }

        return $dataSource;
    }

    /**
     * @param int $entityId
     *
     * @return \Magento\Sales\Model\Order
     */
    public function getOrderByEntityId(int $entityId): Order
    {
        return (ObjectManager::getInstance())->create(Order::class)->load($entityId);
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     *
     * @return string
     */
    public static function getTrackAndTraceLinksAsHtml(Order $order): string
    {
        $html            = '';
        $shippingAddress = $order->getShippingAddress();
        if (! $shippingAddress) {
            return $html;
        }

        $countryId = $shippingAddress->getCountryId() ?? AbstractConsignment::CC_NL;
        $postCode  = $shippingAddress->getPostcode();
        if (! $postCode) {
            return $html;
        }

        $trackData    = $order->getData('track_number') ?? '';
        $trackNumbers = json_decode($trackData, true) ?? $trackData;

        // older shipments are stored with '<br>' as separator between trackNumbers
        if (! is_array($trackNumbers)) {
            $trackNumbers = explode('<br>', $trackNumbers ?? '');
        }

        foreach ($trackNumbers as $trackNumber) {
            switch ($trackNumber) {
                case null:
                case self::VALUE_EMPTY:
                    $html .= '-<br/>';
                    break;
                case self::VALUE_PRINTED:
                    $html .= $trackNumber . '<br/>';
                    break;
                default:
                    $trackTrace = TrackTraceUrl::create($trackNumber, $postCode, $countryId);

                    $html .= sprintf(
                        '<a class="myparcel-barcode-link" target="_blank" href="%1$s">%2$s</a><br/>',
                        $trackTrace,
                        $trackNumber
                    );
            }
        }

        return $html;
    }
}
