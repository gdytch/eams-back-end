<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        // Generate the backend signed URL
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        // Extract query parameters from the signed URL
        $urlParts = parse_url($signedUrl);
        $query = $urlParts['query'] ?? '';

        // Rewrite to frontend URL
        $frontendUrl = config('app.frontend_url').'/verify-email?'.$query;

        $expiryMinutes = config('auth.verification.expire', 60);

        return (new MailMessage)
            ->view(['emails.verify-email', 'emails.verify-email-text'], [
                'name' => $notifiable->first_name ?? $notifiable->name,
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
                'expires' => now()->addMinutes($expiryMinutes)->timestamp,
                'signature' => base64_encode(hash_hmac('sha256', "verification.verify:{$notifiable->getKey()}:".sha1($notifiable->getEmailForVerification()), config('app.key'), true)),
                'expiryMinutes' => $expiryMinutes,
            ]);
    }
}
