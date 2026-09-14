Welcome!

Hi {{ $userName }},

You've been invited to join {{ $organizationName }} as a {{ str_replace('_', ' ', ucfirst($role)) }}. To complete your
setup and access the system, please visit the link below.

YOUR ACCOUNT GIVES YOU:
- Access to the event management system
- Ability to manage events and attendees
- Real-time attendance tracking
- Generate reports and analytics

Create your account here:
{{ $inviteUrl }}

SECURITY NOTE: This invitation link is unique to you. Please do not share it with others. If you did not expect this
email, please contact support at {{ config('branding.support_email') }}.

---

If you have any questions or need assistance, please contact support at {{ config('branding.support_email') }}.

{{ config('branding.footer_text') }}
