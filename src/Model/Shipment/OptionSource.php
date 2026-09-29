<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

/**
 * Where a shipment option's value came from, ranked: a lower tier wins an `excludes` conflict.
 *
 * Ranked by provenance, never by option name, so a new option needs no entry. Two options on the
 * same tier are both kept, and the API refuses the shipment: the module does not choose between
 * two decisions of equal weight. See docs/design/capability-option-dependencies.md.
 */
final class OptionSource
{
    /** A property of the goods, such as an 18+ product. */
    public const PRODUCT = 1;

    /** Posted for this shipment by the operator. */
    public const POSTED = 2;

    /** Chosen by the customer in the checkout. */
    public const CHECKOUT = 3;

    /** The carrier's default_options setting. */
    public const CONFIGURATION = 4;
}
