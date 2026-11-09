<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Merges the label PDFs of several MyParcel accounts into one document.
 *
 * The SDK's ShipmentLabelsService holds one PDF per account. Pages keep the order of the PDFs
 * handed in, so the pages of one account stay together. Selection order is not possible: an A4
 * sheet carries four labels of one account.
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
