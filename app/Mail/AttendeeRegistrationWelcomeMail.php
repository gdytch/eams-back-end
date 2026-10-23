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

class AttendeeRegistrationWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SendsRateLimitedMail, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public Attendee $registration)
    {
        $this->onQueue('mail');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $eventName = $this->registration->event?->name ?? 'Event';

        return new Envelope(
            subject: "You're registered for {$eventName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $this->registration->loadMissing('event');
        $event = $this->registration->event;
        $attendee = $this->registration;

        $sessions = $event?->sessions?->map(fn ($session) => [
            'name' => $session->name,
            'start_time' => $session->startsAt()->format('M d, Y @ g:i A'),
        ])->toArray() ?? [];

        return new Content(
            view: 'emails.event-registration-welcome',
            text: 'emails.event-registration-welcome-text',
            with: [
                'eventName' => $event?->name ?? 'Event',
                'eventOrganization' => $event?->organization?->name,
                'eventStartDate' => $event?->start_date?->format('M d, Y'),
                'eventEndDate' => $event?->end_date?->format('M d, Y'),
                'eventVenue' => $event?->venue,
                'attendeeName' => $attendee?->full_name ?? 'Attendee',
                'sessions' => $sessions,
                // Set when the Attendee has no login yet: the merged welcome + invite mail.
                'inviteUrl' => $attendee->user_id === null ? $attendee->inviteUrl() : null,
            ],
        );
    }
}
