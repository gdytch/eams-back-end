<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Logo Path
    |--------------------------------------------------------------------------
    |
    | Path to the application logo on the public disk. Used in email templates.
    | Ensure php artisan storage:link has been run.
    |
    */

    'logo_path' => env('BRANDING_LOGO_PATH', 'logo/logo.png'),

    /*
    |--------------------------------------------------------------------------
    | Support Email
    |--------------------------------------------------------------------------
    |
    | Email address displayed in email footers for support inquiries.
    |
    */

    'support_email' => env('MAIL_SUPPORT_ADDRESS', 'support@sopum.org'),

    /*
    |--------------------------------------------------------------------------
    | Footer Text
    |--------------------------------------------------------------------------
    |
    | Copyright or footer text displayed in email templates.
    |
    */

    'footer_text' => '© 2026 Event Management System by Southeastern Philippine Union Mission',

    /*
    |--------------------------------------------------------------------------
    | Color Palette (PrimeVue Aura Primary)
    |--------------------------------------------------------------------------
    |
    | Hex color codes used in email templates. All values from the provided
    | PrimeVue primary palette for visual consistency.
    |
    */

    'colors' => [
        'primary' => '#0025ad',      // 500: main action buttons
        'primary_dark' => '#00054d', // 600: header bar, emphasis
        'primary_darker' => '#050a57', // 700: hover states
        'text' => '#1a202c',         // muted text/placeholder (not in palette, sensible default)
        'muted' => '#6b7280',        // secondary text
        'border' => '#e5e7eb',       // light borders
        'bg_light' => '#f9fafb',     // very light backgrounds
    ],

];
