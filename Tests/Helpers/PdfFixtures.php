<?php

declare(strict_types=1);

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Real PDF documents for the label merger, and a way to read back what came out of it.
 *
 * Pages are told apart by their size rather than by their content: a merged page keeps the
 * dimensions it was imported with, and reading text back out of a PDF would need a parser the
 * module does not have.
 */

/**
 * @param float[] $size page size in mm, as FPDF takes it
 */
function makePdf(int $pages, array $size): string
{
    $pdf = new Fpdi();

    for ($page = 1; $page <= $pages; $page++) {
        $pdf->AddPage('P', $size);
    }

    return $pdf->Output('S');
}

/**
 * @return array<int, array{width: int, height: int}> one entry per page, in document order
 */
function pageSizes(string $pdf): array
{
    $reader    = new Fpdi();
    $pageCount = $reader->setSourceFile(StreamReader::createByString($pdf));
    $sizes     = [];

    for ($page = 1; $page <= $pageCount; $page++) {
        $size = $reader->getTemplateSize($reader->importPage($page));

        // Rounded: the millimetres go into the document as points and do not come back exact.
        $sizes[] = ['width' => (int) round($size['width']), 'height' => (int) round($size['height'])];
    }

    return $sizes;
}
