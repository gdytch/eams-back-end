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
        // Generate the backend signed URL with proper Laravel signature
        $expiryMinutes = config('auth.verification.expire', 60);
        $id = $notifiable->getKey();
        $hash = sha1($notifiable->getEmailForVerification());

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($expiryMinutes),
            [
                'id' => $id,
                'hash' => $hash,
            ]
        );

        // Extract query parameters from the signed URL (these contain the real signature and expires)
        $urlParts = parse_url($signedUrl);
        $query = $urlParts['query'] ?? '';

        // Rewrite to frontend URL, including id and hash in the query string
        $frontendUrl = config('app.frontend_url') . '/verify-email?id=' . $id . '&hash=' . $hash . '&' . $query;

        return (new MailMessage)
            ->view(['emails.verify-email', 'emails.verify-email-text'], [
                'name' => $notifiable->first_name ?? $notifiable->name,
                'id' => $id,
                'hash' => $hash,
                'expires' => now()->addMinutes($expiryMinutes)->timestamp,
                'signature' => $query, // Pass the query string with signature and expires
                'expiryMinutes' => $expiryMinutes,
            ]);
    }
}
