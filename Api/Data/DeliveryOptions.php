<?php

namespace MyParcelBE\Magento\Api\Data;

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
     *
     * @return $this
     */
    public function setValue($value);
}
