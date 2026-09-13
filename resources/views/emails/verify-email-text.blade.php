Verify Your Email

Hi {{ $name }},

Thank you for registering! Please verify your email address to activate your account and access all features.

Verify your email using the link below:
{{ config('app.frontend_url') }}/verify-email?id={{ $id }}&hash={{ $hash }}&expires={{ $expires }}&signature={{ $signature }}

This link will expire in {{ $expiryMinutes }} minutes.

---

Didn't create this account?
If you didn't create an account, please contact support at {{ config('branding.support_email') }} to report this issue.

Once verified, you'll be able to register for events and access your attendee dashboard.

Event Management System
{{ config('branding.footer_text') }}
