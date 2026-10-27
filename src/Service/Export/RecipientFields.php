<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

/**
 * The recipient fields both export paths read from a Magento address, so a label and an Order v1
 * order of the same order name the same person at the same postal code.
 */
class RecipientFields
{
    /** The full name as the address shows it: prefix, first, middle and last name, suffix. */
    public function person($address): string
    {
        return trim((string) $address->getName());
    }

    /** The postal code without whitespace. */
    public function postalCode($address): string
    {
        return (string) preg_replace('/\s+/', '', (string) $address->getPostcode());
    }

    /** Get the region code when it has two letters, the only length the API takes as a state. */
    public function state($address): ?string
    {
        $regionCode = (string) $address->getRegionCode();

        return 2 === strlen($regionCode) ? $regionCode : null;
    }
}
