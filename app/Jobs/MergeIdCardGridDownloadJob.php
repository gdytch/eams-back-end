<?php

namespace App\Jobs;

use App\Enums\IdCardGridDownloadStatus;
use App\Models\IdCardGridDownload;
use App\Services\PdfMergeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MergeIdCardGridDownloadJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Merging can take some time.
     */
    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(
        public int $downloadId,
    ) {}

    public function handle(
        PdfMergeService $pdfMergeService
    ): void {
        $download = IdCardGridDownload::with('event')
            ->findOrFail($this->downloadId);

        $event = $download->event;

        try {
            Log::info('Starting ID card grid PDF merge', [
                'download_id' => $download->id,
                'event_id' => $event->id,
                'total_batches' => $download->total_batches,
            ]);

            $download->update([
                'progress_percentage' => 90,
            ]);

            $directory =
                "{$event->id}/id-card-grids/{$download->id}";

            $batchDirectory = "{$directory}/batches";

            /*
             * Build batch paths from the known batch count.
             */
            $batchPaths = [];

            for (
                $batchNumber = 1;
                $batchNumber <= $download->total_batches;
                $batchNumber++
            ) {
                $batchPath =
                    "{$batchDirectory}/batch-{$batchNumber}.pdf";

                if (
                    ! Storage::disk('local')->exists(
                        $batchPath
                    )
                ) {
                    throw new \RuntimeException(
                        "Missing batch PDF: {$batchPath}"
                    );
                }

                $batchPaths[] = $batchPath;

                Log::info('Found ID card grid batch', [
                    'download_id' => $download->id,
                    'batch_number' => $batchNumber,
                    'path' => $batchPath,
                    'size' => Storage::disk('local')
                        ->size($batchPath),
                ]);
            }

            if (empty($batchPaths)) {
                throw new \RuntimeException(
                    'No batch PDFs were found to merge.'
                );
            }

            /*
             * Final PDF.
             *
             * Use download ID instead of event ID to prevent
             * collisions between multiple downloads.
             */
            $finalPath =
                "{$directory}/final.pdf";

            $pdfMergeService->mergeToFile(
                $batchPaths,
                $finalPath,
                'local'
            );

            if (! Storage::disk('local')->exists($finalPath)) {
                throw new \RuntimeException(
                    'Merged PDF was not created.'
                );
            }

            $download->update([
                'progress_percentage' => 98,
            ]);

            Log::info('ID card grid PDF merged', [
                'download_id' => $download->id,
                'final_path' => $finalPath,
                'size' => Storage::disk('local')
                    ->size($finalPath),
            ]);

            /*
             * Delete temporary batch PDFs.
             */
            foreach ($batchPaths as $batchPath) {
                if (
                    Storage::disk('local')->exists(
                        $batchPath
                    )
                ) {
                    Storage::disk('local')->delete(
                        $batchPath
                    );
                }
            }

            /*
             * Delete empty batch directory.
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

            $download->update([
                'status' => IdCardGridDownloadStatus::Completed,
                'file_path' => $finalPath,
                'progress_percentage' => 100,
                'failure_reason' => null,
            ]);

            Log::info(
                'ID card grid download completed',
                [
                    'download_id' => $download->id,
                    'final_path' => $finalPath,
                ]
            );
        } catch (Throwable $e) {
            Log::error(
                'Failed merging ID card grid PDFs',
                [
                    'download_id' => $download->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            $download->update([
                'status' => IdCardGridDownloadStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
