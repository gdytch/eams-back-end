<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AttendeeQrClaimEmailCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your event account email')
            ->greeting('Verify your email')
            ->line("Your verification code is {$this->code}.")
            ->line('This code expires in 15 minutes.');
    }
}
