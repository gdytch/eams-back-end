<?php

namespace App\Jobs;

use App\Enums\IdCardGridDownloadStatus;
use App\Models\IdCardGridDownload;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrepareIdCardGridDownloadJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Number of cards per PDF batch.
     *
     * 5 pages × 4 cards per page = 20 cards.
     */
    private const CARDS_PER_BATCH = 20;

    public int $tries = 3;

    /**
     * This job only prepares the batch jobs, so it should be short.
     */
    public int $timeout = 120;

    public function __construct(
        public int $downloadId,
    ) {}

    public function handle(): void
    {
        $download = IdCardGridDownload::with('event')->findOrFail(
            $this->downloadId
        );

        $event = $download->event;

        try {
            $download->update([
                'status' => IdCardGridDownloadStatus::Processing,
                'progress_percentage' => 0,
                'failure_reason' => null,
            ]);

            /*
             * Get registrations.
             *
             * Keep this query consistent with the existing V2 job.
             */
            $registrations = $event->registrations()
                ->with('attendee')
                ->get();

            Log::info('Preparing ID card grid download', [
                'download_id' => $download->id,
                'event_id' => $event->id,
                'registrations' => $registrations->count(),
            ]);

            /*
             * Keep only registrations whose ID card is ready.
             *
             * Adjust this filtering block if your existing V2 job
             * has additional readiness conditions.
             */
            $readyRegistrations = $registrations->filter(
                fn ($registration) => $registration->attendee &&
                    $registration->id_card_images_path
            )->values();

            if ($readyRegistrations->isEmpty()) {
                throw new \RuntimeException(
                    'No registrations with ready ID cards were found.'
                );
            }

            $download->update([
                'progress_percentage' => 10,
            ]);

            /*
             * Create one queue job per batch.
             *
             * We pass only IDs through the queue, NOT base64 image data.
             */
            $jobs = $readyRegistrations
                ->pluck('id')
                ->chunk(self::CARDS_PER_BATCH)
                ->values()
                ->map(
                    fn ($registrationIds, $index) => new GenerateIdCardGridBatchJob(
                        downloadId: $download->id,
                        batchNumber: $index + 1,
                        registrationIds: $registrationIds->values()->all(),
                    )
                )
                ->all();

            $totalBatches = count($jobs);

            /*
             * Store the total number of batches.
             *
             * If these columns don't exist yet, add them:
             *
             * total_batches
             * completed_batches
             */
            $download->update([
                'total_batches' => $totalBatches,
                'completed_batches' => 0,
            ]);

            Log::info('Dispatching ID card grid batches', [
                'download_id' => $download->id,
                'total_batches' => $totalBatches,
                'registrations' => $readyRegistrations->count(),
            ]);

            /*
             * Laravel coordinates the jobs for us.
             *
             * The merge job is dispatched ONLY after every batch
             * completes successfully.
             */
            Bus::batch($jobs)
                ->name("ID Card Grid Download #{$download->id}")
                ->then(function (Batch $batch) use ($download) {
                    MergeIdCardGridDownloadJob::dispatch(
                        $download->id
                    );
                })
                ->catch(function (
                    Batch $batch,
                    Throwable $exception
                ) use ($download) {
                    Log::error(
                        'ID card grid batch processing failed',
                        [
                            'download_id' => $download->id,
                            'batch_id' => $batch->id,
                            'error' => $exception->getMessage(),
                        ]
                    );

                    $download->refresh();

                    $download->update([
                        'status' => IdCardGridDownloadStatus::Failed,
                        'failure_reason' => $exception->getMessage(),
                    ]);
                })
                ->dispatch();

            Log::info('ID card grid batch dispatched', [
                'download_id' => $download->id,
                'total_batches' => $totalBatches,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed preparing ID card grid download', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $download->update([
                'status' => IdCardGridDownloadStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
