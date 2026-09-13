@component('emails.layouts.base')
    @slot('preheader')
        Verify your email address
    @endslot

    @slot('content')
        <h1>Verify Your Email</h1>

        <p>Hi {{ $name }},</p>

        <p>Thank you for registering! Please verify your email address to activate your account and access all features.</p>

        <p style="margin: 24px 0; text-align: center;">
            @component('emails.components.button')
                @slot('url')
                    {{ config('app.frontend_url') }}/verify-email?id={{ $id }}&hash={{ $hash }}&expires={{ $expires }}&signature={{ $signature }}
                @endslot
                @slot('text')
                    Verify Email
                @endslot
            @endcomponent
        </p>

        <p class="text-muted">This link will expire in {{ $expiryMinutes }} minutes.</p>

        <hr style="border: none; border-top: 1px solid {{ config('branding.colors.border') }}; margin: 24px 0;">

        <p class="text-muted" style="font-size: 14px;">
            <strong>Didn't create this account?</strong>
            <br />
            If you didn't create an account, please <a href="mailto:{{ config('branding.support_email') }}"
                style="color: {{ config('branding.colors.primary') }}; text-decoration: none;">contact support</a> to report this
            issue.
        </p>

        <p class="text-muted">
            Once verified, you'll be able to register for events and access your attendee dashboard.
        </p>
    @endslot
@endcomponent
