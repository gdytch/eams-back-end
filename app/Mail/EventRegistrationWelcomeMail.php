<?php

namespace App\Mail;

use App\Models\EventRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EventRegistrationWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public EventRegistration $registration) {}

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
        $this->registration->loadMissing(['event', 'attendee']);
        $event = $this->registration->event;
        $attendee = $this->registration->attendee;

        $sessions = $event?->sessions?->map(fn ($session) => [
            'name' => $session->name,
            'start_time' => $session->start_time?->format('M d, Y @ g:i A'),
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
            ],
        );
    }
}
