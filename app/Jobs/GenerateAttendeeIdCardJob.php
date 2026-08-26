<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\EventRegistration;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

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
        $this->registration->loadMissing(['attendee', 'event.organization']);

        $attendee = $this->registration->attendee;
        $event = $this->registration->event;
        $organization = $event->organization;

        $qrImage = 'data:image/svg+xml;base64,'.base64_encode(
            QrCode::format('svg')->size(300)->margin(0)->generate($this->registration->qr_token)
        );

        $backgroundImage = $this->encodedBackgroundImage($organization->id_card_background_path);

        $pdf = Pdf::loadView('pdf.attendee-id-card', [
            'qrImage' => $qrImage,
            'backgroundImage' => $backgroundImage,
            'attendeeName' => trim("{$attendee->first_name} {$attendee->last_name}"),
        ])->setPaper([0, 0, 216, 288]); // 3in x 4in, in points (72pt per inch)

        $path = "{$event->id}/{$attendee->id}.pdf";

        Storage::disk('local')->put($path, $pdf->output());

        $this->registration->update([
            'id_card_path' => $path,
            'id_card_generated_at' => now(),
        ]);

        AuditLog::record('event_registration.id_card_generated', $this->registration, ['path' => $path]);
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

        return "data:{$mimeType};base64,".base64_encode(Storage::disk('public')->get($path));
    }
}
