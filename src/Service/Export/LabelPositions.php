<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use MyParcelNL\Magento\Model\Source\PaperType;
use MyParcelNL\Magento\Service\Config;

/**
 * Holds the A4 label positions, and the paper size that follows from them.
 *
 * The SDK reads the paper size from the positions value's *type*: an array is A4 with those slots,
 * a number is A4 from that slot on, anything else is A6. An empty array asks for A4 with nowhere
 * to print, so encode() answers null and the parameter is left off.
 */
class LabelPositions
{
    /** A whole A4 sheet in the modal's checkbox order. Keep it equal to _getLabelPosition() in mass-action.js. */
    public const A4_SHEET = [2, 4, 1, 3];

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Get the admin's configured default positions.
     * Read at the admin's scope, not per order: a selection can span stores, but the printer has one paper size.
     *
     * @return int[]|null null for A6
     */
    public function configured(): ?array
    {
        return PaperType::A4 === $this->config->getGeneralConfig('print/paper_type') ? self::A4_SHEET : null;
    }

    /**
     * @param mixed $positions the export option as it stands, not converted
     *
     * @return string|null null when there is nothing to send
     */
    public function encode($positions): ?string
    {
        return is_array($positions) && $positions ? implode(',', $positions) : null;
    }

    /**
     * @param mixed $raw
     *
     * @return int[]|null
     */
    public function decode($raw): ?array
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        $positions = array_values(array_filter(array_map('intval', explode(',', (string) $raw))));

        return $positions ?: null;
    }
}
