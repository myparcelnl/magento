<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\TrackTrace;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Service\IdList;
use MyParcelNL\Sdk\Services\CountryCodes;

/**
 * Reads the MyParcel track & trace links for a page of orders. Manual tracks are left out. An order
 * without a MyParcel track falls back to the barcodes the status cron wrote to its order row.
 *
 * A link the API issued is stored on the track. TrackTraceUrl builds one for a track without it and
 * for an order-row barcode. Its host follows the account's proposition, hence the store id in the
 * rows; that lookup is made only for a row that needs it.
 */
class LinkResolver
{
    private ResourceConnection $resource;
    private AccountProposition $proposition;
    private TrackTraceUrl      $trackTraceUrl;

    public function __construct(
        ResourceConnection $resource,
        AccountProposition $proposition,
        TrackTraceUrl      $trackTraceUrl
    )
    {
        $this->resource      = $resource;
        $this->proposition   = $proposition;
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

        $withoutTrack = array_values(array_diff($orderIds, array_keys($links)));

        foreach ($withoutTrack ? $this->orderRowsFor($withoutTrack) : [] as $row) {
            foreach (TrackNumber::barcodesFromOrderRow($row['track_number'] ?? null) ?? [] as $barcode) {
                $links[(int) $row['order_id']][] = [
                    'number' => $barcode,
                    'url'    => $this->urlFor(['track_number' => $barcode] + $row),
                ];
            }
        }

        return $links;
    }

    /**
     * The link for one track: the stored one, or the fallback built from its order's address.
     */
    public function forTrack(Track $track): string
    {
        // Without the order first: a usable stored link needs no address, so no query.
        $stored = $this->urlFor(['myparcel_tracktrace_url' => (string) $track->getData('myparcel_tracktrace_url')]);

        if ('' !== $stored) {
            return $stored;
        }

        // Read off the order rather than off its track rows: the track in hand may not be stored
        // yet, and rowsFor() would in any case have filtered out every carrier but ours.
        $order = $this->orderContext((int) $track->getOrderId());

        return $order ? $this->urlFor(['track_number' => (string) $track->getNumber()] + $order) : '';
    }

    /** Render the track and trace links of one order. Do not escape the result, see html(). */
    public function htmlForOrder(int $orderId): string
    {
        return $this->html($this->forOrders([$orderId])[$orderId] ?? []);
    }

    /**
     * Render raw HTML on purpose: the grid binds it with Knockout `html:` and order_view.phtml echoes it.
     * Callers must NOT escape it: every interpolated value is escaped here.
     *
     * @param array<int, array{number: string, url: string}> $links
     */
    public function html(array $links): string
    {
        $html = '';

        foreach ($links as $link) {
            if ('' === $link['number'] || TrackNumber::EMPTY === $link['number']) {
                $html .= '-<br/>';
                continue;
            }

            $number = htmlspecialchars($link['number'], ENT_QUOTES, 'UTF-8');

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
        return self::isLinkable($url) ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : null;
    }

    /** Whether we will link to this url at all. Separate from safeHref(): the email escapes its own. */
    private static function isLinkable(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return 'http' === $scheme || 'https' === $scheme;
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
     * The order rows of orders without a MyParcel track, where the status cron stores the barcodes of
     * an order it shipped without a shipment.
     *
     * @param int[] $orderIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderRowsFor(array $orderIds): array
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
                             ->from(
                                 ['sales_order' => $this->resource->getTableName('sales_order')],
                                 ['order_id' => 'entity_id', 'track_number', 'store_id']
                             );

        $select = $this->joinShippingAddress($select, 'sales_order.entity_id')
                             ->where('sales_order.entity_id IN (?)', $orderIds)
                             ->where('sales_order.track_number LIKE ?', '[%')
        ;

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

        // The column holds whatever the API sent, and the customer email does not go through
        // html(), so a value that fails the allow-list counts as absent.
        if ('' !== $stored && self::isLinkable($stored)) {
            return $stored;
        }

        $barcode  = (string) ($row['track_number'] ?? '');
        $postcode = (string) ($row['postcode'] ?? '');

        if ('' === $postcode || ! TrackNumber::isRealBarcode($barcode)) {
            return '';
        }

        return $this->trackTraceUrl->create(
            $barcode,
            $postcode,
            // an order without a country on its shipping address counts as Dutch: the portal needs
            // a country to resolve the barcode
            (string) ($row['country_id'] ?? '') ?: CountryCodes::CC_NL,
            $this->proposition->forStore(isset($row['store_id']) ? (int) $row['store_id'] : null)
        );
    }
}
