<?php

return [
    // Largest accepted Template image.
    'template_max_bytes' => 2 * 1024 * 1024,

    // Largest accepted original Template PDF.
    'template_pdf_max_bytes' => 25 * 1024 * 1024,

    // Messages the app may send per day before certificate email pauses until tomorrow. Counts all app mail
    // (see DailyMailBudget); keep it under the provider's daily limit (500) to leave room for other mail.
    'email_daily_cap' => (int) env('CERTIFICATE_EMAIL_DAILY_CAP', 400),

    // Most certificate emails one scheduled run sends, so a large backlog trickles out under the provider's rate limit.
    'email_per_run' => (int) env('CERTIFICATE_EMAIL_PER_RUN', 20),
];
