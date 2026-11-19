<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

/**
 * The recipient fields both export paths read from a Magento address, so a label and a PPS order of
 * the same order name the same person at the same postal code.
 */
class RecipientFields
{
    /** The full name as the address shows it: prefix, first, middle and last name, suffix. */
    public static function person($address): string
    {
        return trim((string) $address->getName());
    }

    /** The postal code without whitespace. */
    public static function postalCode($address): string
    {
        return (string) preg_replace('/\s+/', '', (string) $address->getPostcode());
    }
}
