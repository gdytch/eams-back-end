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
        $this->registration->loadMissing(['attendee', 'event']);

        $attendee = $this->registration->attendee;
        $event = $this->registration->event;

        $qrImage = 'data:image/svg+xml;base64,' . base64_encode(
            QrCode::format('svg')->size(300)->margin(0)->generate($this->registration->qr_token)
        );

        $backgroundImage = $this->encodedBackgroundImage($event->id_card_background_path);

        $pdf = Pdf::loadView('pdf.attendee-id-card', [
            'qrImage' => $qrImage,
            'backgroundImage' => $backgroundImage,
            'attendeeName' => trim("{$attendee->first_name} {$attendee->last_name}"),
        ])->setPaper('A4')->setOption('dpi', 300);

        $path = "{$event->id}/{$attendee->id}.pdf";

        Storage::disk('local')->put($path, $pdf->output());

        // Convert PDF to images (one per page)
        $imagePaths = $this->convertPdfToImages($path);

        $this->registration->update([
            'id_card_path' => $path,
            'id_card_images_path' => json_encode($imagePaths),
            'id_card_generated_at' => now(),
        ]);

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

        // Output pattern: {filename}-page-0.png, {filename}-page-1.png, etc.
        $outputPattern = "{$pdfDirectory}/{$pdfFilename}-page-%d.png";

        $process = new Process([
            'convert',
            '-density',
            '300',
            '-quality',
            '85',
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
