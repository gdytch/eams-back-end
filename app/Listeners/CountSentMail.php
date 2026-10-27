<?php

namespace App\Listeners;

use App\Mail\CertificateIssuedMail;
use App\Services\DailyMailBudget;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Counts every message the app sends toward the shared daily cap, except certificate email, which reserves its own slot. */
class CountSentMail
{
    public function __construct(private DailyMailBudget $budget) {}

    public function handle(MessageSent $event): void
    {
        if (($event->data['__laravel_mailable'] ?? null) === CertificateIssuedMail::class) {
            return;
        }

        try {
            $this->budget->record();
        } catch (Throwable $e) {
            Log::warning('Could not count a sent message: '.$e->getMessage());
        }
    }
}
