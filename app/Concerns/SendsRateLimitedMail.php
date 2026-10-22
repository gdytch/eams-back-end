<?php

namespace App\Concerns;

use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * For queued Mailables and Notifications: all mail shares the `mail` rate limiter (AppServiceProvider).
 * Over the limit a job is released back to the queue; retryUntil replaces a try count, since a release counts as an attempt.
 */
trait SendsRateLimitedMail
{
    public function middleware(mixed $notifiable = null, ?string $channel = null): array
    {
        return [new RateLimited('mail')];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /** A failed send is logged only. */
    public function failed(Throwable $e): void
    {
        Log::warning(static::class.' failed to send: '.$e->getMessage());
    }
}
