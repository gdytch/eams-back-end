Registration Confirmed!

Hi {{ $attendeeName }},

Your registration for {{ $eventName }} has been confirmed. We're excited to have you join us!

EVENT DETAILS

Event: {{ $eventName }}
{{ $eventOrganization ? "\nOrganization: " . $eventOrganization : '' }}
{{ $eventStartDate ? "\nStart Date: " . $eventStartDate : '' }}
{{ $eventEndDate ? "\nEnd Date: " . $eventEndDate : '' }}
{{ $eventVenue ? "\nVenue: " . $eventVenue : '' }}

@if (!empty($sessions))
    SESSIONS

    @foreach ($sessions as $session)
        {{ $session['name'] }}{{ isset($session['start_time']) ? ' - ' . $session['start_time'] : '' }}
    @endforeach
@endif
---

Your attendee ID card has been generated and is available in your portal. You'll need it to check in at the event.

View your registration and ID card here:
{{ config('app.frontend_url') }}/dashboard

If you have any questions or need to make changes to your registration, please visit your attendee dashboard or contact
support at {{ config('branding.support_email') }}.

Event Management System
{{ config('branding.footer_text') }}
