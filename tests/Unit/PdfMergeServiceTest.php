<?php

namespace Tests\Unit;

use App\Services\PdfMergeService;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

class PdfMergeServiceTest extends TestCase
{
    public function test_invalid_pdf_is_logged_and_skipped(): void
    {
        Storage::fake('local');
        $validPdf = new Fpdi;
        $validPdf->AddPage();
        $validPdfContent = $validPdf->Output('S');
        Storage::disk('local')->put('valid.pdf', $validPdfContent);
        Storage::disk('local')->put('invalid.pdf', 'not a PDF');
        Storage::disk('local')->put('valid-after-invalid.pdf', $validPdfContent);
        Log::spy();

        $mergedPdf = app(PdfMergeService::class)->merge([
            'valid.pdf',
            'invalid.pdf',
            'valid-after-invalid.pdf',
        ]);

        $this->assertStringStartsWith('%PDF-', $mergedPdf);
        $parsedPdf = new Fpdi;
        $this->assertSame(2, $parsedPdf->setSourceFile(StreamReader::createByString($mergedPdf)));
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Skipping invalid PDF during merge'
                && $context['path'] === 'invalid.pdf'
                && $context['exception'] instanceof Exception
        );
    }
}
