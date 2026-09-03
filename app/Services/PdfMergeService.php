<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

class PdfMergeService
{
    /**
     * Merge multiple PDF files into a single PDF document.
     *
     * @param  array<string>  $pdfPaths  Array of storage disk paths to PDF files
     * @return string Binary PDF content
     */
    public function merge(array $pdfPaths, string $disk = 'local'): string
    {
        $pdf = new Fpdi;

        foreach ($pdfPaths as $pdfPath) {
            if (! Storage::disk($disk)->exists($pdfPath)) {
                continue;
            }

            $pdfContent = Storage::disk($disk)->get($pdfPath);

            try {
                $pageCount = $pdf->setSourceFile(StreamReader::createByString($pdfContent));

                for ($pageNum = 1; $pageNum <= $pageCount; $pageNum++) {
                    $templateId = $pdf->importPage($pageNum);
                    $size = $pdf->getTemplateSize($templateId);

                    $pdf->addPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                    $pdf->useTemplate($templateId);
                }
            } catch (\Exception $e) {
                // Skip PDFs that cannot be parsed
                continue;
            }
        }

        return $pdf->output('S');
    }
}
