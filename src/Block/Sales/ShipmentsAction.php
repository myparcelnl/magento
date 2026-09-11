<?php
/**
 * Block for order actions (multiple orders action and one order action)
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Block\Sales;

/**
 * The shipment grid's MyParcel actions.
 *
 * Everything it needs is OrdersAction's, which OrderAction and ShipmentAction already extend for the
 * same reason. It differs only in which grid it renders on, and therefore which grid the export
 * refreshes.
 */
class ShipmentsAction extends OrdersAction
{
    public function getGridDataSource(): string
    {
        return 'sales_order_shipment_grid.sales_order_shipment_grid_data_source';
    }
}
