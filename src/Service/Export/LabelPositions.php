<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use MyParcelNL\Magento\Service\Config;

/**
 * The A4 label positions, and the paper size that follows from them.
 *
 * The API takes no paper size of its own: the SDK reads it from the positions value's *type* — an
 * array means A4 with those slots, a number means A4 from that slot on, and anything else means A6.
 * So an empty array asks for A4 with nowhere to print, which is why encode() answers null and the
 * parameter is left off rather than sent empty.
 *
 * The value also survives a round trip through an admin URL, which cannot carry an array.
 */
class LabelPositions
{
    /**
     * A whole A4 sheet, in the order the mass-action modal ticks its checkboxes — keep it identical
     * to _getLabelPosition() in mass-action.js.
     */
    public const A4_SHEET = [2, 4, 1, 3];

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * The admin's configured default, for a print that made no choice of its own.
     *
     * Read at the admin's own scope, not per order: paper_type is store-scoped and a selection can
     * span stores, but the paper in the printer is one size.
     *
     * @return int[]|null null for A6
     */
    public function configured(): ?array
    {
        return 'A4' === $this->config->getGeneralConfig('print/paper_type') ? self::A4_SHEET : null;
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
