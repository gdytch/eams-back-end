<div style="border-bottom: 1px solid {{ config('branding.colors.border') }}; padding: 12px 0;">
    <div
        style="color: {{ config('branding.colors.muted') }}; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
        {{ $label }}
    </div>
    <div style="color: {{ config('branding.colors.text') }}; font-size: 16px; font-weight: 500;">
        {{ $value }}
    </div>
</div>
