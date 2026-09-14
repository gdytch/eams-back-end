@component('emails.layouts.base')
    @slot('preheader')
        You're registered for {{ $eventName }}
    @endslot

    @slot('content')
        <h1>Registration Confirmed!</h1>

        <p>Hi {{ $attendeeName }},</p>

        <p>Your registration for <strong>{{ $eventName }}</strong> has been confirmed. We're excited to have you join us!</p>

        <h2>Event Details</h2>

        <div
            style="background-color: {{ config('branding.colors.bg_light') }}; border-left: 4px solid {{ config('branding.colors.primary') }}; padding: 16px; border-radius: 4px; margin: 16px 0;">
            @component('emails.components.info-row')
                @slot('label')
                    Event
                @endslot
                @slot('value')
                    {{ $eventName }}
                @endslot
            @endcomponent

            @if ($eventOrganization)
                @component('emails.components.info-row')
                    @slot('label')
                        Organization
                    @endslot
                    @slot('value')
                        {{ $eventOrganization }}
                    @endslot
                @endcomponent
            @endif

            @if ($eventStartDate)
                @component('emails.components.info-row')
                    @slot('label')
                        Start Date
                    @endslot
                    @slot('value')
                        {{ $eventStartDate }}
                    @endslot
                @endcomponent
            @endif

            @if ($eventEndDate)
                @component('emails.components.info-row')
                    @slot('label')
                        End Date
                    @endslot
                    @slot('value')
                        {{ $eventEndDate }}
                    @endslot
                @endcomponent
            @endif

            @if ($eventVenue)
                @component('emails.components.info-row')
                    @slot('label')
                        Venue
                    @endslot
                    @slot('value')
                        {{ $eventVenue }}
                    @endslot
                @endcomponent
            @endif
        </div>

        @if (!empty($sessions))
            <h2>Sessions</h2>
            <ul style="list-style: none; padding: 0; margin: 0;">
                @foreach ($sessions as $session)
                    <li style="padding: 8px 0; color: {{ config('branding.colors.text') }}; font-size: 16px;">
                        <strong>{{ $session['name'] }}</strong>
                        @if ($session['start_time'])
                            <br /> <span class="text-muted">{{ $session['start_time'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <hr style="border: none; border-top: 1px solid {{ config('branding.colors.border') }}; margin: 24px 0;">

        <p>Your attendee ID card has been generated and is available in your portal. You'll need it to check in at the event.
        </p>

        <p style="margin: 24px 0; text-align: center;">
            @component('emails.components.button')
                @slot('url')
                    {{ config('app.frontend_url') }}
                @endslot
                @slot('text')
                    View My Registration
                @endslot
            @endcomponent
        </p>

        <p class="text-muted">
            If you have any questions or need to make changes to your registration, please visit your attendee dashboard or <a
                href="mailto:{{ config('branding.support_email') }}"
                style="color: {{ config('branding.colors.primary') }}; text-decoration: none;">contact support</a>.
        </p>
    @endslot
@endcomponent
