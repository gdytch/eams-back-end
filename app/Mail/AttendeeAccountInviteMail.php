<?php

namespace App\Mail;

use App\Concerns\SendsRateLimitedMail;
use App\Models\Attendee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AttendeeAccountInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SendsRateLimitedMail, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public Attendee $attendee)
    {
        $this->onQueue('mail');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $organization = $this->attendee->organization?->name ?? 'Event Management System';

        return new Envelope(
            subject: "Create your {$organization} attendee account",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $this->attendee->loadMissing('organization');

        $inviteUrl = $this->attendee->inviteUrl();

        return new Content(
            view: 'emails.attendee-account-invite',
            text: 'emails.attendee-account-invite-text',
            with: [
                'attendeeName' => $this->attendee->full_name,
                'organizationName' => $this->attendee->organization?->name,
                'inviteUrl' => $inviteUrl,
            ],
        );
    }
}
