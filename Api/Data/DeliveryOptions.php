<?php
/**
 * Created by PhpStorm.
 * User: richardperdaan
 * Date: 2019-05-14
 * Time: 13:22
 */
namespace MyParcelNL\Magento\Api\Data;
interface DeliveryOptions
{
    const VALUE = 'value';
    /**
     * Return value.
     *
     * @return string|null
     */
    public function getValue();
    /**
     * Set value.
     *
     * @param string|null $value
     * @return $this
     */
    public function setValue($value);
}