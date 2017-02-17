<?php
/**
 * Test to check if labels can create
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\magento\Test\Unit\Model\Adminhtml\Order;

include_once('../../../Constants.php');

use MyParcelNL\magento\Test\Unit\Constants;

class CreateLabelsTest extends Constants
{
    protected function setUp()
    {
        parent::setUp();

    }


    public function testExecute()
    {
        $order1Id = $this->setOrder();
        $order2Id = $this->setOrder();
        $response = $this->createLabel($order1Id . ',' . $order2Id);

        $this->equalTo(true, preg_match("/^%PDF-1./", $response));
    }

    /**
     * @param $orderId
     *
     * @return mixed
     */
    private function createLabel($orderId)
    {
        $data = [
            'selected_ids' => $orderId,
            'mypa_request_type' => 'download',
            'paper_size' => 'A4',
            'mypa_positions' => '1',
        ];
        $response = $this->sendGetRequest($this->getCreateLabelUrl() . '?' . http_build_query($data));

        return $response;
    }
}
