<?php

namespace App\Jobs;

use App\Models\IdCardGridDownload;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class GenerateIdCardGridBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    /**
     * 10 minutes.
     */
    public int $timeout = 600;

    public int $tries = 3;

    /**
     * 20 cards per batch:
     *
     * 4 cards/page
     * 5 pages/batch
     */
    private const CARDS_PER_PAGE = 4;

    public function __construct(
        public int $downloadId,
        public int $batchNumber,
        public array $registrationIds,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $download = IdCardGridDownload::with('event')
            ->findOrFail($this->downloadId);

        $event = $download->event;

        try {
            Log::info('Generating ID card grid batch', [
                'download_id' => $download->id,
                'batch_number' => $this->batchNumber,
                'registration_count' => count($this->registrationIds),
            ]);

            /*
             * Load ONLY the registrations for this batch.
             */
            $registrations = $event->registrations()
                ->with('attendee')
                ->whereIn('id', $this->registrationIds)
                ->get()
                ->keyBy('id');

            /*
             * Preserve the original registration order.
             */
            $registrations = collect($this->registrationIds)
                ->map(
                    fn ($id) => $registrations->get($id)
                )
                ->filter()
                ->values();

            /*
             * Convert only this batch's cards.
             */
            $imageDataUris = [];

            foreach ($registrations as $registration) {
                if (
                    ! $registration->attendee ||
                    ! $registration->id_card_images_path
                ) {
                    continue;
                }

                $path = $registration->id_card_images_path[0] ?? null;

                $extension = strtolower(
                    pathinfo($path, PATHINFO_EXTENSION)
                );

                /*
                 * If the card is already an image, use it directly.
                 */
                if (
                    in_array(
                        $extension,
                        ['jpg', 'jpeg', 'png', 'webp']
                    )
                ) {
                    $disk = Storage::disk('local');

                    if (! $disk->exists($path)) {
                        Log::warning(
                            'ID card image not found',
                            [
                                'registration_id' => $registration->id,
                                'path' => $path,
                            ]
                        );

                        continue;
                    }

                    $mime = match ($extension) {
                        'jpg', 'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'webp' => 'image/webp',
                        default => 'image/png',
                    };

                    $imageDataUris[] = "data:{$mime};base64,".
                        base64_encode(
                            $disk->get($path)
                        );

                    continue;
                }

                /*
                 * PDF → image.
                 */
                if ($extension === 'pdf') {
                    $image = $this->convertPdfToImage($path);

                    if ($image === null) {
                        continue;
                    }

                    $imageDataUris[] = $image;
                }
            }

            if (empty($imageDataUris)) {
                throw new \RuntimeException(
                    "No valid ID cards found for batch {$this->batchNumber}."
                );
            }

            /*
             * Four cards per page.
             */
            $pages = collect($imageDataUris)
                ->chunk(self::CARDS_PER_PAGE)
                ->map(fn ($page) => $page->values()->all())
                ->values()
                ->all();

            $totalPages = count($pages);

            Log::info('Rendering ID card grid batch', [
                'download_id' => $download->id,
                'batch_number' => $this->batchNumber,
                'cards' => count($imageDataUris),
                'pages' => $totalPages,
            ]);

            /*
             * Render ONLY this batch.
             */
            $pdf = Pdf::loadView(
                'pdf.id-card-grid',
                [
                    'event' => $event,
                    'pages' => $pages,
                    'batchNumber' => $this->batchNumber,
                ]
            )->setPaper('a4');

            $pdfContent = $pdf->output();

            /*
             * Each download gets its own directory.
             *
             * This prevents two simultaneous downloads for the
             * same event from overwriting batch-1.pdf, etc.
             */
            $directory =
                "{$event->id}/id-card-grids/{$download->id}";

            $batchDirectory = "{$directory}/batches";

            Storage::disk('local')->makeDirectory(
                $batchDirectory
            );

            $batchPath =
                "{$batchDirectory}/batch-{$this->batchNumber}.pdf";

            Storage::disk('local')->put(
                $batchPath,
                $pdfContent
            );

            Log::info('ID card grid batch completed', [
                'download_id' => $download->id,
                'batch_number' => $this->batchNumber,
                'path' => $batchPath,
                'size' => strlen($pdfContent),
            ]);

            /*
             * Update completed batch count.
             *
             * We use a fresh model so concurrent workers don't
             * overwrite each other's values.
             */
            $download->refresh();

            $download->increment('completed_batches');

            $download->refresh();

            $totalBatches = max(
                1,
                (int) $download->total_batches
            );

            $completedBatches = min(
                $download->completed_batches,
                $totalBatches
            );

            /*
             * Generation phase = 10% → 90%.
             */
            $progress = 10 + (int) (
                ($completedBatches / $totalBatches) * 80
            );

            $download->update([
                'progress_percentage' => min($progress, 90),
            ]);
        } catch (Throwable $e) {
            Log::error('ID card grid batch failed', [
                'download_id' => $this->downloadId,
                'batch_number' => $this->batchNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Convert a PDF ID card into a PNG data URI.
     */
    private function convertPdfToImage(
        string $path
    ): ?string {
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            Log::warning('ID card PDF not found', [
                'path' => $path,
            ]);

            return null;
        }

        $temporaryPdf = tempnam(
            sys_get_temp_dir(),
            'id-card-'
        );

        $temporaryPng = tempnam(
            sys_get_temp_dir(),
            'id-card-'
        ).'.png';

        try {
            file_put_contents(
                $temporaryPdf,
                $disk->get($path)
            );

            $process = new Process([
                'convert',
                '-density',
                '150',
                "{$temporaryPdf}[0]",
                '-quality',
                '90',
                $temporaryPng,
            ]);

            /*
             * ImageMagick conversion timeout.
             */
            $process->setTimeout(120);

            $process->run();

            if (! $process->isSuccessful()) {
                Log::error(
                    'Failed converting ID card PDF to image',
                    [
                        'path' => $path,
                        'error' => $process->getErrorOutput(),
                    ]
                );

                return null;
            }

            if (! file_exists($temporaryPng)) {
                return null;
            }

            return 'data:image/png;base64,'.
                base64_encode(
                    file_get_contents($temporaryPng)
                );
        } finally {
            if ($temporaryPdf && file_exists($temporaryPdf)) {
                @unlink($temporaryPdf);
            }

            if ($temporaryPng && file_exists($temporaryPng)) {
                @unlink($temporaryPng);
            }
        }
    }
}
