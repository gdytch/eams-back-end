@component('emails.layouts.base')
    @slot('preheader')
        You've been invited to join {{ $organizationName }}
    @endslot

    @slot('content')
        <h1>Welcome!</h1>

        <p>Hi {{ $userName }},</p>

        <p>You've been invited to join <strong>{{ $organizationName }}</strong> as a
            <strong>{{ str_replace('_', ' ', ucfirst($role)) }}</strong>. To complete your setup and access the system, please
            create your account by clicking the button below.</p>

        <div
            style="background-color: {{ config('branding.colors.bg_light') }}; border-left: 4px solid {{ config('branding.colors.primary') }}; padding: 16px; border-radius: 4px; margin: 24px 0;">
            <p style="margin: 0; color: {{ config('branding.colors.text') }}; font-size: 14px;">
                <strong>Your account gives you:</strong>
            </p>
            <ul style="margin: 12px 0 0 0; padding-left: 20px; color: {{ config('branding.colors.text') }}; font-size: 14px;">
                <li>Access to the event management system</li>
                <li>Ability to manage events and attendees</li>
                <li>Real-time attendance tracking</li>
                <li>Generate reports and analytics</li>
            </ul>
        </div>

        <p style="margin: 24px 0; text-align: center;">
            @component('emails.components.button')
                @slot('url')
                    {{ $inviteUrl }}
                @endslot
                @slot('text')
                    Create My Account
                @endslot
            @endcomponent
        </p>

        <p style="color: {{ config('branding.colors.muted') }}; font-size: 14px;">
            <strong>Security Note:</strong> This invitation link is unique to you. Please do not share it with others. If you
            did not expect this email, please <a href="mailto:{{ config('branding.support_email') }}"
                style="color: {{ config('branding.colors.primary') }}; text-decoration: none;">contact support</a>.
        </p>

        <hr style="border: none; border-top: 1px solid {{ config('branding.colors.border') }}; margin: 24px 0;">

        <p class="text-muted" style="color: {{ config('branding.colors.muted') }}; font-size: 14px;">
            If you have any questions or need assistance, please <a href="mailto:{{ config('branding.support_email') }}"
                style="color: {{ config('branding.colors.primary') }}; text-decoration: none;">contact support</a>.
        </p>
    @endslot
@endcomponent
