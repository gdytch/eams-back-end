<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\EventRegistration;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\Process\Process;

class GenerateAttendeeIdCardJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public EventRegistration $registration) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->registration->loadMissing(['attendee', 'event', 'attendee.union', 'attendee.mission', 'attendee.church']);

        $attendee = $this->registration->attendee;
        $event = $this->registration->event;

        $qrImage = 'data:image/svg+xml;base64,' . base64_encode(
            QrCode::format('svg')->size(300)->margin(0)->generate($this->registration->qr_token)
        );

        $backgroundImage = $this->encodedBackgroundImage($event->id_card_background_path);
        $fontColor = $event->id_card_font_color ?? '#000000';

        $pdf = Pdf::loadView('pdf.attendee-id-card', [
            'qrImage' => $qrImage,
            'backgroundImage' => $backgroundImage,
            'attendeeName' => trim("{$attendee->first_name} {$attendee->last_name}"),
            'attendeeTerritory' => $attendee->territory ?? null,
            'fontColor' => $fontColor,
        ])->setPaper([0, 0, 234, 342]); // 3.25in x 4.75in, in points (72pt per inch)

        $path = "{$event->id}/{$attendee->id}.pdf";

        Storage::disk('local')->put($path, $pdf->output());

        // Convert PDF to images (one per page)
        $imagePaths = $this->convertPdfToImages($path);

        // Only update with valid image paths
        $updateData = [
            'id_card_path' => $path,
            'id_card_generated_at' => now(),
        ];

        if (! empty($imagePaths) && is_array($imagePaths)) {
            $updateData['id_card_images_path'] = $imagePaths;
        }

        $this->registration->update($updateData);

        AuditLog::record('event_registration.id_card_generated', $this->registration, [
            'path' => $path,
            'image_paths' => $imagePaths,
        ]);
    }

    /**
     * Base64 data URI for the organization's background image, or null if none is set.
     */
    private function encodedBackgroundImage(?string $path): ?string
    {
        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mimeType = Storage::disk('public')->mimeType($path);

        return "data:{$mimeType};base64," . base64_encode(Storage::disk('public')->get($path));
    }

    /**
     * Convert PDF to images (one per page) using ImageMagick's convert command.
     *
     * @return array<int, string> Array of storage paths to generated images
     */
    private function convertPdfToImages(string $pdfPath): array
    {
        $pdfFullPath = Storage::disk('local')->path($pdfPath);
        $pdfDirectory = dirname($pdfFullPath);
        $pdfFilename = pathinfo($pdfPath, PATHINFO_FILENAME);

        // Output pattern: {filename}-page-0.jpg, {filename}-page-1.jpg, etc.
        $imagesDir = "{$pdfDirectory}/images";
        $outputPattern = "{$imagesDir}/{$pdfFilename}-page-%d.jpg";

        // Ensure the images directory exists
        if (! is_dir($imagesDir)) {
            mkdir($imagesDir, 0755, true);
        }

        // Clean up old images from previous generations
        $page = 0;
        while (file_exists(sprintf($outputPattern, $page))) {
            unlink(sprintf($outputPattern, $page));
            $page++;
        }

        $process = new Process([
            'convert',
            '-density',
            '600',
            '-quality',
            '90',
            '-format',
            'jpg',
            $pdfFullPath,
            $outputPattern,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException("Failed to convert PDF to images: {$process->getErrorOutput()}");
        }

        // Collect generated image paths
        $imagePaths = [];
        $page = 0;

        while (file_exists(sprintf($outputPattern, $page))) {
            $imagePath = "{$pdfPath}/" . basename(sprintf($outputPattern, $page));
            // Normalize to storage path (relative to storage/app)
            $imagePath = str_replace(Storage::disk('local')->path(''), '', sprintf($outputPattern, $page));
            $imagePaths[] = $imagePath;
            $page++;
        }

        return $imagePaths;
    }
}
