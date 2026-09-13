Reset Your Password

Hi {{ $name }},

We received a request to reset the password for your account. If you didn't make this request, you can ignore this
email.

Reset your password using the link below:
{{ config('app.frontend_url') }}/reset-password?token={{ $token }}&email={{ urlencode($email) }}

This link will expire in {{ $expiryMinutes }} minutes.

---

Didn't request a password reset?
If you didn't initiate this request, please contact support immediately at {{ config('branding.support_email') }}.

For security reasons, never share this link with anyone. Our support team will never ask for your password.

Event Management System
{{ config('branding.footer_text') }}
