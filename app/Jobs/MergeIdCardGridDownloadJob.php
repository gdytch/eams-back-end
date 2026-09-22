<?php

namespace App\Jobs;

use App\Enums\IdCardGridDownloadStatus;
use App\Models\IdCardGridDownload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

class MergeIdCardGridDownloadJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $downloadId,
    ) {}

    public function handle(): void
    {
        $download = IdCardGridDownload::with('event')
            ->findOrFail($this->downloadId);

        $event = $download->event;

        try {
            Log::info('Starting ID card grid ZIP archive', [
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
                    'No batch PDFs were found to archive.'
                );
            }

            $finalPath = "{$directory}/id-card-grid-batches.zip";
            $temporaryPath = "{$finalPath}.tmp";
            $disk = Storage::disk('local');

            $download->update([
                'progress_percentage' => 95,
            ]);

            Log::info('Creating ID card grid ZIP archive', [
                'download_id' => $download->id,
                'batch_count' => count($batchPaths),
            ]);

            $disk->delete($temporaryPath);

            $archive = new ZipArchive;
            $openResult = $archive->open(
                $disk->path($temporaryPath),
                ZipArchive::CREATE | ZipArchive::OVERWRITE
            );

            if ($openResult !== true) {
                throw new \RuntimeException(
                    "Unable to create ZIP archive (error {$openResult})."
                );
            }

            try {
                foreach ($batchPaths as $batchPath) {
                    $archiveEntry = basename($batchPath);

                    if (! $archive->addFile(
                        $disk->path($batchPath),
                        $archiveEntry
                    )) {
                        throw new \RuntimeException(
                            "Unable to add batch PDF to ZIP archive: {$batchPath}"
                        );
                    }

                    if (! $archive->setCompressionName($archiveEntry, ZipArchive::CM_STORE)) {
                        throw new \RuntimeException(
                            "Unable to configure batch PDF in ZIP archive: {$batchPath}"
                        );
                    }
                }

                if (! $archive->close()) {
                    throw new \RuntimeException('Unable to finalize ZIP archive.');
                }
            } catch (Throwable $e) {
                $archive->close();
                $disk->delete($temporaryPath);

                throw $e;
            }

            if (! $disk->exists($temporaryPath) || $disk->size($temporaryPath) === 0) {
                throw new \RuntimeException(
                    'ZIP archive was not created.'
                );
            }

            $disk->delete($finalPath);

            if (! $disk->move($temporaryPath, $finalPath)) {
                throw new \RuntimeException('Unable to move the ZIP archive into place.');
            }

            $download->update([
                'progress_percentage' => 98,
            ]);

            Log::info('ID card grid ZIP archive created', [
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
                'Failed archiving ID card grid PDFs',
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

    public function failed(?Throwable $exception): void
    {
        $failureReason = $exception?->getMessage()
            ?? 'The ZIP archive worker stopped unexpectedly.';

        IdCardGridDownload::query()
            ->whereKey($this->downloadId)
            ->update([
                'status' => IdCardGridDownloadStatus::Failed,
                'failure_reason' => $failureReason,
            ]);

        Log::error('ID card grid archive job failed permanently', [
            'download_id' => $this->downloadId,
            'error' => $failureReason,
        ]);
    }
}
