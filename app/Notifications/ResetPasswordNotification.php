<?php

namespace App\Notifications;

use App\Concerns\SendsRateLimitedMail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable, SendsRateLimitedMail;

    /** Auth mail goes first: workers drain `high` before `mail`. */
    public function viaQueues(): array
    {
        return ['mail' => 'high'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $expiryMinutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->view(['emails.password-reset', 'emails.password-reset-text'], [
                'name' => $notifiable->first_name ?? $notifiable->name,
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
                'expiryMinutes' => $expiryMinutes,
            ]);
    }
}
