<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The shared per-day count of messages sent through the app, which the mail provider caps (500 a day).
 * Every message is recorded by CountSentMail; certificate email reserves its slot first and consults the cap.
 */
class DailyMailBudget
{
    public function used(): int
    {
        return (int) DB::table('daily_mail_counts')->where('day', $this->day())->value('sent');
    }

    /** Add already-sent messages, ignoring the cap. */
    public function record(int $count = 1): void
    {
        $this->ensureRow();
        DB::table('daily_mail_counts')->where('day', $this->day())->increment('sent', $count);
    }

    /** Take one slot unless the cap is reached; atomic, so concurrent runs cannot exceed it. */
    public function reserve(int $cap): bool
    {
        $this->ensureRow();

        return DB::table('daily_mail_counts')->where('day', $this->day())->where('sent', '<', $cap)->increment('sent') === 1;
    }

    /** Give back a reserved slot when the message was not sent. */
    public function release(): void
    {
        DB::table('daily_mail_counts')->where('day', $this->day())->where('sent', '>', 0)->decrement('sent');
    }

    private function ensureRow(): void
    {
        DB::table('daily_mail_counts')->insertOrIgnore(['day' => $this->day(), 'sent' => 0]);
    }

    private function day(): string
    {
        return now()->toDateString();
    }
}
