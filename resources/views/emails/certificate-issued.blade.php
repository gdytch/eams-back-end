@component('emails.layouts.base')
    @slot('preheader')
        Your {{ $title }} for {{ $eventName }}
    @endslot

    @slot('content')
        <h1>Your certificate is ready</h1>

        <p>Hi {{ $attendeeName }},</p>

        <p>Thank you for being part of <strong>{{ $eventName }}</strong>. Your <strong>{{ $title }}</strong> is attached to
            this email as a PDF.</p>

        <hr style="border: none; border-top: 1px solid {{ config('branding.colors.border') }}; margin: 24px 0;">

        <p class="text-muted" style="color: {{ config('branding.colors.muted') }}; font-size: 14px;">
            If you have any questions, please <a href="mailto:{{ config('branding.support_email') }}"
                style="color: {{ config('branding.colors.primary') }}; text-decoration: none;">contact support</a>.
        </p>
    @endslot
@endcomponent
