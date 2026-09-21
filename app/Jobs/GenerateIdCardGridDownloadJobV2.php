<?php

namespace App\Jobs;

use App\Enums\IdCardGridDownloadStatus;
use App\Models\AuditLog;
use App\Models\IdCardGridDownload;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\PdfMergeService;
use Symfony\Component\Process\Process;
use Throwable;

class GenerateIdCardGridDownloadJobV2 implements ShouldQueue
{
    use Queueable;

    /**
     * Number of pages rendered by DomPDF per batch.
     *
     * 5 pages × 4 cards per page = 20 cards per batch.
     */
    private const PAGES_PER_BATCH = 5;

    public function __construct(
        public IdCardGridDownload $download
    ) {}

    /**
     * Safely extract image paths, handling JSON string or array format.
     */
    private function extractImages($images): array
    {
        if (is_string($images)) {
            $images = json_decode($images, true) ?? [];
        }

        if (! is_array($images)) {
            $images = [];
        }

        return $images;
    }

    /**
     * Safely update download status.
     */
    private function updateDownloadStatus(array $data): void
    {
        try {
            $this->download->update($data);
        } catch (\Exception $e) {
            // If progress_percentage column doesn't exist yet,
            // try again without it.
            if (str_contains($e->getMessage(), 'progress_percentage')) {
                unset($data['progress_percentage']);

                $this->download->update($data);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Update progress while safely preventing values outside 0-100.
     */
    private function updateProgress(int $progress): void
    {
        $progress = max(0, min(100, $progress));

        $this->updateDownloadStatus([
            'progress_percentage' => $progress,
        ]);
    }

    /**
     * Convert a PDF's first page to PNG.
     */
    private function convertPdfToImage(string $pdfPath): ?string
    {
        $tempImagePath = sys_get_temp_dir()
            . '/'
            . uniqid('card_', true)
            . '.png';

        $process = new Process([
            'convert',
            '-density',
            '150',
            '-quality',
            '85',
            "{$pdfPath}[0]",
            $tempImagePath,
        ]);

        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::error('Failed to convert ID card PDF to image', [
                'pdf_path' => $pdfPath,
                'error' => $process->getErrorOutput(),
            ]);

            @unlink($tempImagePath);

            return null;
        }

        if (! file_exists($tempImagePath)) {
            Log::error('PDF conversion completed but PNG was not created', [
                'pdf_path' => $pdfPath,
                'temp_image_path' => $tempImagePath,
            ]);

            return null;
        }

        return $tempImagePath;
    }

    public function handle(PdfMergeService $pdfMergeService): void
    {
        $batchPaths = [];

        try {
            /*
             * -------------------------------------------------------------
             * 0–10%
             * Load and validate registrations
             * -------------------------------------------------------------
             */

            $this->updateDownloadStatus([
                'status' => IdCardGridDownloadStatus::Processing,
                'progress_percentage' => 0,
            ]);

            $event = $this->download->event;

            $query = $event
                ->registrations()
                ->with('attendee');

            // Filter to requested registrations,
            // or all registrations if registration_ids is null.
            if ($this->download->registration_ids !== null) {
                $query->whereIn(
                    'id',
                    $this->download->registration_ids
                );
            }

            $allRegistrations = $query->get();

            Log::info('IdCardGridDownloadJobV2: Processing registrations', [
                'event_id' => $event->id,
                'download_id' => $this->download->id,
                'count' => $allRegistrations->count(),
            ]);

            /*
             * Find registrations that already have either:
             *
             * 1. ID card image
             * 2. ID card PDF
             */

            $readyRegistrations = $allRegistrations->filter(
                function ($reg) {
                    $images = $this->extractImages(
                        $reg->id_card_images_path
                    );

                    $hasImage = ! empty($images)
                        && Storage::disk('local')->exists($images[0]);

                    $hasPdf = $reg->id_card_path !== null
                        && Storage::disk('local')->exists(
                            $reg->id_card_path
                        );

                    return $hasImage || $hasPdf;
                }
            )->values();

            Log::info(
                'IdCardGridDownloadJobV2: Ready registrations found',
                [
                    'download_id' => $this->download->id,
                    'count' => $readyRegistrations->count(),
                ]
            );

            $this->updateProgress(10);

            /*
             * -------------------------------------------------------------
             * No cards available
             * -------------------------------------------------------------
             */

            if ($readyRegistrations->isEmpty()) {
                $failureReason =
                    'No ID cards (PDFs or images) were ready. '
                    . 'Ensure GenerateAttendeeIdCardJob has run for each registration.';

                $this->updateDownloadStatus([
                    'status' => IdCardGridDownloadStatus::Failed,
                    'failure_reason' => $failureReason,
                    'progress_percentage' => 0,
                    'completed_at' => now(),
                ]);

                AuditLog::record(
                    'event_registration.id_card_grid_download_failed',
                    $event,
                    [
                        'download_id' => $this->download->id,
                        'reason' => 'No ready cards',
                    ]
                );

                return;
            }

            /*
             * -------------------------------------------------------------
             * 10–40%
             * Prepare images
             * -------------------------------------------------------------
             */

            $totalReady = $readyRegistrations->count();

            $processed = 0;

            $imageDataUris = collect();

            foreach ($readyRegistrations as $reg) {
                $images = $this->extractImages(
                    $reg->id_card_images_path
                );

                $dataUri = null;

                /*
                 * Prefer existing image.
                 */
                if (
                    ! empty($images)
                    && Storage::disk('local')->exists($images[0])
                ) {
                    $imagePath = $images[0];

                    $content = Storage::disk('local')->get(
                        $imagePath
                    );

                    $mimeType = Storage::disk('local')->mimeType(
                        $imagePath
                    );

                    $dataUri =
                        "data:{$mimeType};base64,"
                        . base64_encode($content);
                }

                /*
                 * Fall back to PDF conversion.
                 */ elseif (
                    $reg->id_card_path !== null
                    && Storage::disk('local')->exists(
                        $reg->id_card_path
                    )
                ) {
                    $pdfPath = Storage::disk('local')->path(
                        $reg->id_card_path
                    );

                    $tempImagePath = $this->convertPdfToImage(
                        $pdfPath
                    );

                    if ($tempImagePath !== null) {
                        $content = file_get_contents(
                            $tempImagePath
                        );

                        @unlink($tempImagePath);

                        if ($content !== false) {
                            $dataUri =
                                'data:image/png;base64,'
                                . base64_encode($content);
                        }
                    }
                }

                /*
                 * Only add successfully processed cards.
                 */
                if ($dataUri !== null) {
                    $imageDataUris->put(
                        $reg->id,
                        $dataUri
                    );
                }

                $processed++;

                /*
                 * 10–40%
                 */
                $progress = 10 + (int) (
                    ($processed / $totalReady) * 30
                );

                $this->updateProgress($progress);
            }

            if ($imageDataUris->isEmpty()) {
                throw new \RuntimeException(
                    'Failed to process ID cards. '
                        . 'No images could be prepared.'
                );
            }

            /*
             * Only registrations that successfully produced an image.
             */
            $readyRegistrations = $readyRegistrations
                ->filter(
                    fn($reg) => $imageDataUris->has($reg->id)
                )
                ->values();

            /*
             * -------------------------------------------------------------
             * Create pages
             *
             * 4 cards per page.
             * -------------------------------------------------------------
             */

            $pages = $readyRegistrations
                ->map(
                    fn($reg) => $imageDataUris->get($reg->id)
                )
                ->chunk(4)
                ->map(
                    fn($chunk) => $chunk->values()->all()
                )
                ->values();

            $totalPages = $pages->count();

            if ($totalPages === 0) {
                throw new \RuntimeException(
                    'No PDF pages could be created.'
                );
            }

            Log::info(
                'IdCardGridDownloadJobV2: Pages prepared',
                [
                    'download_id' => $this->download->id,
                    'registrations' => $readyRegistrations->count(),
                    'pages' => $totalPages,
                ]
            );

            /*
             * We no longer need this after creating $pages.
             */
            unset($imageDataUris);

            /*
             * -------------------------------------------------------------
             * 40–90%
             * Generate PDF batches
             * -------------------------------------------------------------
             */

            $directory =
                "{$event->id}/id-card-grids";

            $batchDirectory =
                "{$directory}/batches";

            if (! Storage::disk('local')->exists($batchDirectory)) {
                Storage::disk('local')->makeDirectory(
                    $batchDirectory
                );
            }

            $pageBatches = $pages->chunk(
                self::PAGES_PER_BATCH
            );

            $totalBatches = $pageBatches->count();

            foreach ($pageBatches as $batchIndex => $batchPages) {
                $batchNumber = $batchIndex + 1;

                Log::info(
                    'IdCardGridDownloadJobV2: Generating PDF batch',
                    [
                        'download_id' => $this->download->id,
                        'batch' => $batchNumber,
                        'total_batches' => $totalBatches,
                        'pages' => $batchPages->count(),
                    ]
                );

                /*
                 * Render only this batch.
                 */
                $pdf = Pdf::loadView(
                    'pdf.id-card-grid',
                    [
                        'event' => $event,
                        'pages' => $batchPages
                            ->values()
                            ->all(),
                    ]
                )->setPaper('a4');

                /*
                 * Generate the PDF output.
                 */
                $pdfContent = $pdf->output();

                /*
                 * Explicitly release DomPDF object before
                 * processing the next batch.
                 */
                unset($pdf);

                $batchPath =
                    "{$batchDirectory}/batch-{$batchNumber}.pdf";

                Storage::disk('local')->put(
                    $batchPath,
                    $pdfContent
                );

                unset($pdfContent);

                $batchPaths[] = $batchPath;

                /*
                 * 40–90%
                 */
                $progress = 40 + (int) (
                    ($batchNumber / $totalBatches) * 50
                );

                $this->updateProgress($progress);

                Log::info(
                    'IdCardGridDownloadJobV2: PDF batch completed',
                    [
                        'download_id' => $this->download->id,
                        'batch' => $batchNumber,
                        'progress' => $progress,
                    ]
                );

                /*
                 * Release the pages from this batch.
                 */
                unset($batchPages);

                /*
                 * Give PHP a chance to release memory.
                 */
                gc_collect_cycles();
            }

            /*
             * -------------------------------------------------------------
             * 90%
             * Start merging
             * -------------------------------------------------------------
             */

            $this->updateProgress(90);

            Log::info(
                'IdCardGridDownloadJobV2: Starting PDF merge',
                [
                    'download_id' => $this->download->id,
                    'batch_count' => count($batchPaths),
                ]
            );

            /*
             * -------------------------------------------------------------
             * 90–98%
             * Merge all batches
             * -------------------------------------------------------------
             */

            $finalPath = "{$directory}/{$this->download->event->id}-merged.pdf";
            foreach ($batchPaths as $batchPath) {
                $exists = Storage::disk('local')->exists($batchPath);

                Log::info('Generated batch PDF', [
                    'path' => $batchPath,
                    'exists' => $exists,
                    'size' => $exists
                        ? Storage::disk('local')->size($batchPath)
                        : null,
                ]);
            }

            $finalPdfContent = $pdfMergeService->merge(
                $batchPaths,
                'local'
            );

            Storage::disk('local')->put(
                $finalPath,
                $finalPdfContent
            );
            unset($finalPdfContent);
            $this->updateProgress(98);

            /*
             * -------------------------------------------------------------
             * Delete temporary batch PDFs
             * -------------------------------------------------------------
             */

            foreach ($batchPaths as $batchPath) {
                if (file_exists($batchPath)) {
                    @unlink($batchPath);
                }
            }

            /*
             * Remove temporary batch directory.
             */
            if (
                Storage::disk('local')->exists(
                    $batchDirectory
                )
            ) {
                Storage::disk('local')->deleteDirectory(
                    $batchDirectory
                );
            }

            /*
             * -------------------------------------------------------------
             * 100%
             * Complete
             * -------------------------------------------------------------
             */

            $this->updateDownloadStatus([
                'status' => IdCardGridDownloadStatus::Completed,
                'file_path' => $finalPath,
                'progress_percentage' => 100,
                'completed_at' => now(),
            ]);

            AuditLog::record(
                'event_registration.id_card_grid_download_completed',
                $event,
                [
                    'download_id' => $this->download->id,
                    'registration_count' =>
                    $readyRegistrations->count(),
                    'page_count' => $totalPages,
                    'batch_count' => $totalBatches,
                    'file_path' => $finalPath,
                ]
            );

            Log::info(
                'IdCardGridDownloadJobV2: Completed successfully',
                [
                    'download_id' => $this->download->id,
                    'pages' => $totalPages,
                    'batches' => $totalBatches,
                    'file_path' => $finalPath,
                ]
            );
        } catch (Throwable $e) {
            /*
             * -------------------------------------------------------------
             * Cleanup temporary batch PDFs after failure
             * -------------------------------------------------------------
             */

            foreach ($batchPaths as $batchPath) {
                try {
                    if (file_exists($batchPath)) {
                        @unlink($batchPath);
                    }
                } catch (Throwable $cleanupException) {
                    Log::warning(
                        'Failed to cleanup temporary PDF batch',
                        [
                            'path' => $batchPath,
                            'error' =>
                            $cleanupException->getMessage(),
                        ]
                    );
                }
            }

            /*
             * -------------------------------------------------------------
             * Update failed status
             * -------------------------------------------------------------
             */

            try {
                $this->download->refresh();

                $event = $this->download->event;

                $this->updateDownloadStatus([
                    'status' => IdCardGridDownloadStatus::Failed,
                    'failure_reason' =>
                    $e->getMessage(),
                    'progress_percentage' => 0,
                    'completed_at' => now(),
                ]);

                AuditLog::record(
                    'event_registration.id_card_grid_download_failed',
                    $event,
                    [
                        'download_id' => $this->download->id,
                        'error' => $e->getMessage(),
                    ]
                );
            } catch (Throwable $innerException) {
                /*
                 * Last resort.
                 */
                Log::error(
                    'IdCardGridDownloadJobV2: Failed to update status',
                    [
                        'download_id' => $this->download->id,
                        'original_error' =>
                        $e->getMessage(),
                        'update_error' =>
                        $innerException->getMessage(),
                    ]
                );

                try {
                    \DB::table(
                        'id_card_grid_downloads'
                    )
                        ->where(
                            'id',
                            $this->download->id
                        )
                        ->update([
                            'status' =>
                            IdCardGridDownloadStatus::Failed->value,
                            'failure_reason' =>
                            'Job failed: '
                                . substr(
                                    $e->getMessage(),
                                    0,
                                    255
                                ),
                            'completed_at' => now(),
                        ]);
                } catch (Throwable $dbException) {
                    Log::error(
                        'IdCardGridDownloadJobV2: Critical database failure',
                        [
                            'download_id' =>
                            $this->download->id,
                            'error' =>
                            $dbException->getMessage(),
                        ]
                    );
                }
            }

            /*
             * Log the original exception with stack trace.
             */
            Log::error(
                'IdCardGridDownloadJobV2: Job failed',
                [
                    'download_id' => $this->download->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
        }
    }
}
