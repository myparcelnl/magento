<?php
/**
 * Test to check if order can create
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelBE\magento\Test\Unit\Model\Adminhtml\Order;

include_once ('../../../Constants.php');

use MyParcelBE\magento\Test\Unit\Constants;

class CreateOrderTest extends Constants
{
    protected function setUp()
    {
        parent::setUp();
    }

    public function testExecute()
    {
        $orderId = $this->setOrder();
        $this->assertEquals(true, is_numeric($orderId));
    }
}
