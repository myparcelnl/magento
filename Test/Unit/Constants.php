<?php
/**
 * Contains standard Class Constants and Methods
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

namespace MyParcelNL\magento\Test\Unit;


class Constants extends \PHPUnit_Framework_TestCase
{
    const BASE_URL = "http://127.0.0.1:81/magento/";
    const API_PREFIX = "rest/V1/";

    private $token = '';

    const USERNAME = "new-admin";

    const PASSWORD = "!admin123!";

    protected function setUp()
    {
        error_reporting(E_ALL);
        ini_set("display_errors", 1);

        $this->setToken();
    }

    public function sendGetRequest($uri)
    {
        $ch = curl_init(self::BASE_URL . $uri);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json", "Authorization: Bearer " . json_decode($this->token)
        ]);

        $result = curl_exec($ch);

        return $result;
    }

    public function sendPostRequest($uri, $body = '')
    {
        $ch = curl_init(self::BASE_URL . $uri);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json", "Authorization: Bearer " . json_decode($this->token),
            "Content-Lenght: " . strlen($body)
        ]);

        return curl_exec($ch);
    }

    public function sendPutRequest($uri, $body = '')
    {
        $ch = curl_init(self::BASE_URL . $uri);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json", "Authorization: Bearer " . json_decode($this->token),
            "Content-Lenght: " . strlen($body)
        ]);

        return curl_exec($ch);
    }

    protected function setToken()
    {
        $userData = ["username" => self::USERNAME, "password" => self::PASSWORD];
        $result = $this->sendPostRequest(self::API_PREFIX . 'integration/admin/token', json_encode($userData));

        $this->token = $result;

    }

    protected function setOrder()
    {
        $productSku = $this->getProductSku();
        $cardId = json_decode($this->createCard());
        $this->addItemToCard($cardId, $productSku);
        $this->addBillingAddress($cardId);
        $result = $this->convertToOrder($cardId);
        $orderId = json_decode($result);

        return $orderId;
    }

    protected function getProduct($productAlias = 'test')
    {
        $product = $this->sendGetRequest(self::API_PREFIX . 'products/' . $productAlias);
        $product = json_decode($product);

        return $product;
    }

    protected function getProductSku()
    {
        $sku = $this->getProduct()->sku;

        return $sku;
    }

    protected function createCard()
    {

        $cardId = $this->sendPostRequest(self::API_PREFIX . 'guest-carts');

        return $cardId;
    }

    protected function addItemToCard($cardId, $sku)
    {
        $data = [
            "cartItem" => [
                "quote_id" => $cardId,
                "sku" => $sku,
                "qty" => 1,
            ],
        ];

        return $this->sendPostRequest(self::API_PREFIX . 'guest-carts/' . $cardId . '/items', json_encode($data));
    }

    private function addBillingAddress($cardId)
    {
        $data = ["addressInformation" => [
            "shipping_address" => [
                "email" => "reindert@myparcel.nl",
                "countryId" => "NL",
                "regionId" => "0",
                "region" => "",
                "street" => [
                    "Siriusdreef 55",
                ],
                "company" => "MyParcel",
                "telephone" => "123456",
                "postcode" => "2231 je",
                "city" => "Rijnsburg",
                "firstname" => "hoi",
                "lastname" => "yes",
            ],
            "billing_address" => [
                "countryId" => "NL",
                "regionId" => "0",
                "region" => "",
                "street" => [
                    "Siriusdreef 55",
                ],
                "company" => "MyParcel",
                "telephone" => "123456",
                "postcode" => "2231 je",
                "city" => "Rijnsburg",
                "firstname" => "asdf",
                "lastname" => "MyParcel- asdfsd",
                "saveInAddressBook" => null,
                "email" => "reindert-test@myparcel.nl",
            ], "shipping_method_code" => "flatrate",
            "shipping_carrier_code" => "flatrate",
        ]];

        return $this->sendPostRequest(
            self::API_PREFIX . 'guest-carts/' . $cardId . '/shipping-information',
            json_encode($data)
        );
    }

    private function convertToOrder($cardId)
    {
        $data = json_encode([
            "paymentMethod" => [
                "method" => 'checkmo',
            ],
        ]);

        return $this->sendPutRequest(self::API_PREFIX . 'guest-carts/' . $cardId . '/order', $data);
    }

    /**
     * Get controller url
     *
     * @return string
     */
    protected function getCreateLabelUrl()
    {
        return 'admin/myparcelnl/order/CreateAndPrintMyParcelTrack/';
    }
}
