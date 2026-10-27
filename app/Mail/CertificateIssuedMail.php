<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * The Issued certificate PDF as an attachment. Sent directly (not queued) by the throttled certificate email
 * command, which needs to know whether the send worked before it marks the row emailed.
 */
class CertificateIssuedMail extends Mailable
{
    public string $attachmentName;

    public function __construct(
        public string $pdf,
        public string $title,
        public string $attendeeName,
        public string $eventName,
    ) {
        $this->attachmentName = (Str::slug("{$title} {$attendeeName}") ?: 'certificate').'.pdf';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->title} for {$this->eventName}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.certificate-issued',
            text: 'emails.certificate-issued-text',
            with: ['attendeeName' => $this->attendeeName, 'eventName' => $this->eventName, 'title' => $this->title],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->attachmentName)->withMime('application/pdf')];
    }
}
