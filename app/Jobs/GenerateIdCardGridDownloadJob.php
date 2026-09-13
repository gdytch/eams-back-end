<?php

namespace App\Jobs;

use App\Enums\IdCardGridDownloadStatus;
use App\Models\AuditLog;
use App\Models\IdCardGridDownload;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class GenerateIdCardGridDownloadJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public IdCardGridDownload $download) {}

    /**
     * Safely extract image paths, handling JSON string or array format
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
     * Safely update download status, gracefully handling missing progress_percentage column
     */
    private function updateDownloadStatus(array $data): void
    {
        try {
            $this->download->update($data);
        } catch (\Exception $e) {
            // If progress_percentage column doesn't exist yet (migration not run),
            // try again without it
            if (str_contains($e->getMessage(), 'progress_percentage')) {
                unset($data['progress_percentage']);
                $this->download->update($data);
            } else {
                throw $e;
            }
        }
    }

    public function handle(): void
    {
        try {
            $this->updateDownloadStatus([
                'status' => IdCardGridDownloadStatus::Processing,
                'progress_percentage' => 0,
            ]);

            $event = $this->download->event;
            $query = $event->registrations()->with('attendee');

            // Filter to requested registrations, or all if registration_ids is null
            if ($this->download->registration_ids !== null) {
                $query->whereIn('id', $this->download->registration_ids);
            }

            $allRegistrations = $query->get();

            // Log diagnostic info for debugging
            \Log::info("IdCardGridDownloadJob: Processing {$allRegistrations->count()} registrations", [
                'event_id' => $event->id,
                'download_id' => $this->download->id,
                'registration_ids' => $allRegistrations->pluck('id')->all(),
            ]);

            // Detailed registration state logging
            $allRegistrations->each(function ($reg) {
                $images = $this->extractImages($reg->id_card_images_path);

                \Log::info("Registration {$reg->id} ID card state", [
                    'has_pdf' => $reg->id_card_path ? 'yes' : 'no',
                    'pdf_path' => $reg->id_card_path,
                    'pdf_exists' => $reg->id_card_path ? Storage::disk('local')->exists($reg->id_card_path) : false,
                    'has_images' => ! empty($images) ? 'yes' : 'no',
                    'images_count' => count($images),
                    'first_image_exists' => (! empty($images) && count($images) > 0)
                        ? Storage::disk('local')->exists($images[0])
                        : false,
                ]);
            });

            // Filter to registrations with either ready images OR a PDF file
            $readyRegistrations = $allRegistrations->filter(function ($reg) {
                $images = $this->extractImages($reg->id_card_images_path);

                return (! empty($images) && Storage::disk('local')->exists($images[0]))
                    ||
                    ($reg->id_card_path !== null && Storage::disk('local')->exists($reg->id_card_path));
            });

            \Log::info("IdCardGridDownloadJob: Found {$readyRegistrations->count()} registrations with ready files", [
                'download_id' => $this->download->id,
            ]);

            $this->updateDownloadStatus(['progress_percentage' => 10]);

            if ($readyRegistrations->isEmpty()) {
                $failureReason = 'No ID cards (PDFs or images) were ready. Ensure GenerateAttendeeIdCardJob has run for each registration.';
                $this->updateDownloadStatus([
                    'status' => IdCardGridDownloadStatus::Failed,
                    'failure_reason' => $failureReason,
                    'progress_percentage' => 0,
                    'completed_at' => now(),
                ]);

                \Log::warning('IdCardGridDownloadJob: No ready cards', [
                    'download_id' => $this->download->id,
                    'event_id' => $event->id,
                    'total_registrations' => $allRegistrations->count(),
                ]);

                AuditLog::record('event_registration.id_card_grid_download_failed', $event, [
                    'download_id' => $this->download->id,
                    'reason' => 'No ready cards',
                ]);

                return;
            }

            // Base64-encode each image (or PDF converted to image)
            $totalReady = $readyRegistrations->count();
            $processed = 0;
            $imageDataUris = $readyRegistrations->mapWithKeys(function ($reg) use (&$processed, $totalReady) {
                $images = $this->extractImages($reg->id_card_images_path);

                // Prefer image if available, fall back to PDF
                if (! empty($images) && Storage::disk('local')->exists($images[0])) {
                    $imagePath = $images[0];
                    $content = Storage::disk('local')->get($imagePath);
                    $mimeType = Storage::disk('local')->mimeType($imagePath);
                } else {
                    // Convert PDF to temporary PNG image
                    $pdfPath = Storage::disk('local')->path($reg->id_card_path);
                    $tempImagePath = sys_get_temp_dir() . '/' . uniqid('card_') . '.png';

                    $process = new Process([
                        'convert',
                        '-density',
                        '150',
                        '-quality',
                        '85',
                        "{$pdfPath}[0]",
                        $tempImagePath,
                    ]);
                    $process->run();

                    if (! $process->isSuccessful()) {
                        // If conversion fails, skip this registration
                        $processed++;
                        $progress = 10 + (int) (($processed / $totalReady) * 60);
                        $this->updateDownloadStatus(['progress_percentage' => $progress]);

                        return [$reg->id => null];
                    }

                    $content = file_get_contents($tempImagePath);
                    @unlink($tempImagePath);
                    $mimeType = 'image/png';
                }

                $processed++;
                $progress = 10 + (int) (($processed / $totalReady) * 60);
                $this->updateDownloadStatus(['progress_percentage' => $progress]);

                return [
                    $reg->id => "data:{$mimeType};base64," . base64_encode($content),
                ];
            })->filter(fn($uri) => $uri !== null); // Remove any that failed to convert

            // Rebuild readyRegistrations to only include those that were successfully encoded
            $readyRegistrations = $readyRegistrations->filter(
                fn($reg) => isset($imageDataUris[$reg->id])
            );

            if ($imageDataUris->isEmpty()) {
                $this->updateDownloadStatus([
                    'status' => IdCardGridDownloadStatus::Failed,
                    'failure_reason' => 'Failed to process ID cards (could not convert PDFs to images).',
                    'progress_percentage' => 0,
                    'completed_at' => now(),
                ]);

                AuditLog::record('event_registration.id_card_grid_download_failed', $event, [
                    'download_id' => $this->download->id,
                    'reason' => 'PDF conversion failed',
                ]);

                return;
            }

            // Chunk registrations into groups of 4 (2x2 grid per page)
            $this->updateDownloadStatus(['progress_percentage' => 70]);

            $pages = $readyRegistrations
                ->map(fn($reg) => $imageDataUris[$reg->id])
                ->chunk(4)
                ->map(fn($chunk) => $chunk->values()->all())
                ->values()
                ->all();

            $pdf = Pdf::loadView('pdf.id-card-grid', [
                'event' => $event,
                'pages' => $pages,
            ])->setPaper('a4');

            $this->updateDownloadStatus(['progress_percentage' => 90]);

            $directory = "{$event->id}/id-card-grids";
            if (! Storage::disk('local')->exists($directory)) {
                Storage::disk('local')->makeDirectory($directory);
            }

            $path = "{$directory}/{$this->download->id}.pdf";
            Storage::disk('local')->put($path, $pdf->output());

            $this->updateDownloadStatus([
                'status' => IdCardGridDownloadStatus::Completed,
                'file_path' => $path,
                'progress_percentage' => 100,
                'completed_at' => now(),
            ]);

            AuditLog::record('event_registration.id_card_grid_download_completed', $event, [
                'download_id' => $this->download->id,
                'registration_count' => $readyRegistrations->count(),
                'file_path' => $path,
            ]);
        } catch (\Exception $e) {
            // Ensure we have the latest model state and event relationship
            try {
                $this->download->refresh();
                $event = $this->download->event;

                $this->updateDownloadStatus([
                    'status' => IdCardGridDownloadStatus::Failed,
                    'failure_reason' => $e->getMessage(),
                    'progress_percentage' => 0,
                    'completed_at' => now(),
                ]);

                AuditLog::record('event_registration.id_card_grid_download_failed', $event, [
                    'download_id' => $this->download->id,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $innerException) {
                // If update itself fails, log it but don't throw
                \Log::error('IdCardGridDownloadJob: Failed to update job status after error', [
                    'download_id' => $this->download->id,
                    'original_error' => $e->getMessage(),
                    'update_error' => $innerException->getMessage(),
                ]);

                // Last resort: try a direct database update without progress_percentage
                try {
                    $updateData = [
                        'status' => IdCardGridDownloadStatus::Failed->value,
                        'failure_reason' => 'Job failed: ' . substr($e->getMessage(), 0, 255),
                        'completed_at' => now(),
                    ];

                    \DB::table('id_card_grid_downloads')->where('id', $this->download->id)->update($updateData);
                } catch (\Exception $dbException) {
                    \Log::error('IdCardGridDownloadJob: Critical - could not update job status via database', [
                        'download_id' => $this->download->id,
                        'error' => $dbException->getMessage(),
                    ]);
                }
            }
        }
    }
}
