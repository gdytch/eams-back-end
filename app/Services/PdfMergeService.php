<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Symfony\Component\Process\Process;

class PdfMergeService
{
    /**
     * Merge PDF files directly to a storage path without loading them into PHP memory.
     *
     * @param  array<string>  $pdfPaths
     */
    public function mergeToFile(
        array $pdfPaths,
        string $outputPath,
        string $disk = 'local'
    ): void {
        $storage = Storage::disk($disk);
        $temporaryOutputPath = "{$outputPath}.tmp";
        $storage->delete($temporaryOutputPath);

        $command = ['pdftk'];

        foreach ($pdfPaths as $pdfPath) {
            if (! $storage->exists($pdfPath)) {
                throw new RuntimeException("PDF file not found: {$pdfPath}");
            }

            $command[] = $storage->path($pdfPath);
        }

        $command = [
            ...$command,
            'cat',
            'output',
            $storage->path($temporaryOutputPath),
        ];

        $process = new Process($command);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            $storage->delete($temporaryOutputPath);

            throw new RuntimeException(
                'PDF merge failed: '.trim($process->getErrorOutput())
            );
        }

        if (! $storage->exists($temporaryOutputPath) || $storage->size($temporaryOutputPath) === 0) {
            $storage->delete($temporaryOutputPath);

            throw new RuntimeException('PDF merge produced an empty output file.');
        }

        $storage->delete($outputPath);

        if (! $storage->move($temporaryOutputPath, $outputPath)) {
            throw new RuntimeException('Unable to move the merged PDF into place.');
        }
    }

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
