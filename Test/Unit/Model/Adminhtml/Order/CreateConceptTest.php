<?php
/**
 * Create MyParcel concept and check if track exist in Magento
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
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class CreateConceptTest extends Constants
{
    protected function setUp()
    {
        parent::setUp();
    }


    public function testExecute()
    {
         $orderId = $this->setOrder();
        $response = $this->createConcept($orderId);

        // Check if track has consignment id
        $order = $this->sendGetRequest(self::API_PREFIX . 'orders/' . $orderId);
        $response = json_decode($order, true);

        $this->assertEquals(true, key_exists('shipping', $response['extension_attributes']['shipping_assignments'][0]));
    }

    /**
     * @param $orderId
     *
     * @return mixed
     */
    private function createConcept($orderId)
    {
        $data = [
            'selected_ids' => $orderId,
            'mypa_request_type' => 'concept',
        ];
        $response = $this->sendGetRequest($this->getCreateLabelUrl() . '?' . http_build_query($data));

        return $response;
    }
}
