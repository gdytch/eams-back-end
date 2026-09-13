@component('emails.layouts.base')
    @slot('preheader')
        Reset your password
    @endslot

    @slot('content')
        <h1>Reset Your Password</h1>

        <p>Hi {{ $name }},</p>

        <p>We received a request to reset the password for your account. If you didn't make this request, you can ignore this
            email.</p>

        <p style="margin: 24px 0; text-align: center;">
            @component('emails.components.button')
                @slot('url')
                    {{ config('app.frontend_url') }}/reset-password?token={{ $token }}&email={{ urlencode($email) }}
                @endslot
                @slot('text')
                    Reset Password
                @endslot
            @endcomponent
        </p>

        <p class="text-muted">This link will expire in {{ $expiryMinutes }} minutes.</p>

        <hr style="border: none; border-top: 1px solid {{ config('branding.colors.border') }}; margin: 24px 0;">

        <p class="text-muted" style="font-size: 14px;">
            <strong>Didn't request a password reset?</strong>
            <br />
            If you didn't initiate this request, please <a href="mailto:{{ config('branding.support_email') }}"
                style="color: {{ config('branding.colors.primary') }}; text-decoration: none;">contact support</a> immediately.
        </p>

        <p class="text-muted">
            For security reasons, never share this link with anyone. Our support team will never ask for your password.
        </p>
    @endslot
@endcomponent
