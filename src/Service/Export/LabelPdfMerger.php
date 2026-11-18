<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\LogContext;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

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
     * Skips a document FPDI cannot parse, so the other labels still download.
     *
     * @param string[] $pdfs raw PDF documents, in the order they should appear
     *
     * @return array{pdf: string, skipped: int} the merged document, and the number of skipped documents
     */
    public function merge(array $pdfs): array
    {
        $pdfs = array_values(array_filter($pdfs, static fn(string $pdf): bool => '' !== $pdf));

        if (count($pdfs) < 2) {
            return ['pdf' => $pdfs[0] ?? '', 'skipped' => 0];
        }

        $merged   = new Fpdi();
        $imported = array_map(fn(string $pdf): ?array => $this->templatesOf($merged, $pdf), $pdfs);
        $pages    = array_merge(...array_values(array_filter($imported)));
        $skipped  = count($imported) - count(array_filter($imported));

        foreach ($pages as [$template, $size]) {
            $merged->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $merged->useTemplate($template);
        }

        // FPDF adds a blank page to a document without pages
        return ['pdf' => $pages ? $merged->Output('S') : '', 'skipped' => $skipped];
    }

    /**
     * Import every page of one document, or null when FPDI cannot parse it.
     *
     * @return array<int,array{0: string, 1: array}>|null the template id and size of each page
     */
    private function templatesOf(Fpdi $merged, string $pdf): ?array
    {
        try {
            $templates = [];
            $pageCount = $merged->setSourceFile(StreamReader::createByString($pdf));

            for ($page = 1; $page <= $pageCount; $page++) {
                $template    = $merged->importPage($page);
                $templates[] = [$template, $merged->getTemplateSize($template)];
            }

            return $templates;
        } catch (Throwable $e) {
            Logger::warning('MyParcel export: one label PDF could not be merged', LogContext::of($e));

            return null;
        }
    }
}
