<?php
/**
 * Created by PhpStorm.
 * User: richardperdaan
 * Date: 2019-03-05
 * Time: 14:41
 */

namespace MyParcelNL\Magento\Api\Data;

interface DeliveryOptionsInRestApi
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