<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Merges the label PDFs of several MyParcel accounts into one document (TR-000006).
 *
 * beta.15 merged inside MyParcelCollection::setPdfOfLabels(); v11's ShipmentLabelsService holds one
 * PDF string per instance and has no cross-account concept, so a mixed batch would otherwise be one
 * download per account. Page order follows the order the PDFs are handed in, which is the admin's
 * selection order rather than account grouping.
 */
class LabelPdfMerger
{
    /**
     * @param string[] $pdfs raw PDF documents, in the order they should appear
     */
    public function merge(array $pdfs): string
    {
        $pdfs = array_values(array_filter($pdfs, static fn(string $pdf): bool => '' !== $pdf));

        if (! $pdfs) {
            return '';
        }

        if (1 === count($pdfs)) {
            return $pdfs[0];
        }

        $merged = new Fpdi();

        foreach ($pdfs as $pdf) {
            $pageCount = $merged->setSourceFile(StreamReader::createByString($pdf));

            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $merged->importPage($page);
                $size     = $merged->getTemplateSize($template);

                $merged->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $merged->useTemplate($template);
            }
        }

        return $merged->Output('S');
    }
}
