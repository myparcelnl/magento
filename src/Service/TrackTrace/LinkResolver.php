<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\TrackTrace;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Service\TrackTraceUrl;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Magento\Service\IdList;

/**
 * Every MyParcel track & trace link an admin sees, read for a whole page of orders in one query.
 * Only this carrier's tracks: an order can also carry manual ones, which belong to no portal here.
 *
 * The link the API issued is stored on the track; TrackTraceUrl only fills in for shipments exported
 * before that was so, and its host depends on the account's platform — hence the store id in the
 * rows. That lookup is made only when a row actually needs the fallback, so an install with nothing
 * but fresh exports never reads account settings while rendering a grid.
 */
class LinkResolver
{
    private ResourceConnection $resource;
    private AccountPlatform    $platform;
    private TrackTraceUrl      $trackTraceUrl;

    public function __construct(
        ResourceConnection $resource,
        AccountPlatform    $platform,
        TrackTraceUrl      $trackTraceUrl
    )
    {
        $this->resource      = $resource;
        $this->platform      = $platform;
        $this->trackTraceUrl = $trackTraceUrl;
    }

    /**
     * @param int[] $orderIds
     *
     * @return array<int, array<int, array{number: string, url: string}>> keyed by order id
     */
    public function forOrders(array $orderIds): array
    {
        $orderIds = IdList::ints($orderIds);

        if (! $orderIds) {
            return [];
        }

        $links = [];

        foreach ($this->rowsFor($orderIds) as $row) {
            $links[(int) $row['order_id']][] = [
                'number' => (string) ($row['track_number'] ?? ''),
                'url'    => $this->urlFor($row),
            ];
        }

        return $links;
    }

    /**
     * The link for one track: the stored one, or the fallback built from its order's address.
     */
    public function forTrack(Track $track): string
    {
        $stored = (string) $track->getData('myparcel_tracktrace_url');

        if ('' !== $stored) {
            return $stored;
        }

        // Read off the order rather than off its track rows: the track in hand may not be stored
        // yet, and rowsFor() would in any case have filtered out every carrier but ours.
        $row = $this->orderContext((int) $track->getOrderId());

        if (! $row) {
            return '';
        }

        $row['track_number'] = (string) $track->getNumber();

        return $this->urlFor($row);
    }

    public function htmlForOrder(int $orderId): string
    {
        return $this->html($this->forOrders([$orderId])[$orderId] ?? []);
    }

    /**
     * Raw HTML on purpose: the grid renders it through a Knockout `html:` binding and
     * order_view.phtml echoes it unescaped. Callers must NOT escape it — every interpolated value
     * is escaped here.
     *
     * @param array<int, array{number: string, url: string}> $links
     */
    public function html(array $links): string
    {
        $html = '';

        foreach ($links as $link) {
            if ('' === $link['number'] || TrackAndTrace::VALUE_EMPTY === $link['number']) {
                $html .= '-<br/>';
                continue;
            }

            $number = htmlspecialchars($link['number'], ENT_QUOTES, 'UTF-8');

            if ('' === $link['url']) {
                $html .= $number . '<br/>';
                continue;
            }

            $href = self::safeHref($link['url']);

            if (null === $href) {
                $html .= $number . '<br/>';
                continue;
            }

            $html .= sprintf(
                '<a class="myparcel-barcode-link" target="_blank" rel="noopener" href="%1$s">%2$s</a><br/>',
                $href,
                $number
            );
        }

        return $html;
    }

    /**
     * The url as an href value, or null when it is not one we will link to.
     *
     * Framework\Escaper would be the idiomatic choice, but escapeUrl() reaches the global
     * ObjectManager for translate-inline, which a service cannot do under test. htmlspecialchars
     * alone stops attribute breakout but not a `javascript:` scheme, and this value arrives from
     * the api.
     */
    private static function safeHref(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
    /**
     * The order's shipping address, for a fallback URL. $orderIdColumn is a column name this class
     * supplies, never input.
     */
    private function joinShippingAddress(Select $select, string $orderIdColumn): Select
    {
        $connection = $this->resource->getConnection();

        return $select->joinLeft(
            ['address' => $this->resource->getTableName('sales_order_address')],
            $connection->quoteInto(
                "address.parent_id = $orderIdColumn AND address.address_type = ?",
                'shipping'
            ),
            ['postcode', 'country_id']
        );
    }

    /**
     * @param int[] $orderIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(array $orderIds): array
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
                             ->from(
                                 ['track' => $this->resource->getTableName('sales_shipment_track')],
                                 ['order_id', 'track_number', 'myparcel_tracktrace_url']
                             )
                             ->joinLeft(
                                 ['sales_order' => $this->resource->getTableName('sales_order')],
                                 'sales_order.entity_id = track.order_id',
                                 ['store_id']
                             );

        $select = $this->joinShippingAddress($select, 'track.order_id')
                             ->where('track.order_id IN (?)', $orderIds)
                             ->order('track.entity_id ASC')
        ;

        MyParcelTracks::scopeSelect($select, 'track');

        return $connection->fetchAll($select);
    }

    /**
     * The store and shipping address a fallback URL is built from.
     *
     * @return array<string, mixed> empty when the order is gone
     */
    private function orderContext(int $orderId): array
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
                             ->from(
                                 ['sales_order' => $this->resource->getTableName('sales_order')],
                                 ['store_id']
                             );

        $select = $this->joinShippingAddress($select, 'sales_order.entity_id')
                             ->where('sales_order.entity_id = ?', $orderId)
        ;

        return $connection->fetchRow($select) ?: [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function urlFor(array $row): string
    {
        $stored = (string) ($row['myparcel_tracktrace_url'] ?? '');

        if ('' !== $stored) {
            return $stored;
        }

        $barcode  = (string) ($row['track_number'] ?? '');
        $postcode = (string) ($row['postcode'] ?? '');

        if ('' === $postcode || ! TrackAndTrace::isRealBarcode($barcode)) {
            return '';
        }

        return $this->trackTraceUrl->create(
            $barcode,
            $postcode,
            // An order with no country on its shipping address was read as Dutch before this moved
            // here; the portal needs a country to resolve the barcode.
            (string) ($row['country_id'] ?? '') ?: CountryCode::CC_NL,
            $this->platform->forStore(isset($row['store_id']) ? (int) $row['store_id'] : null)
        );
    }
}
