<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserAccountInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        $organization = $this->user->organization?->name ?? 'Event Management System';

        return new Envelope(
            subject: "You've been invited to join {$organization}",
        );
    }

    public function content(): Content
    {
        $this->user->loadMissing('organization');

        $inviteUrl = config('app.frontend_url') . '/accept-invite?token=' . $this->user->invite_token . '&email=' . urlencode($this->user->email);

        return new Content(
            view: 'emails.user-account-invite',
            text: 'emails.user-account-invite-text',
            with: [
                'userName' => $this->user->first_name ?? $this->user->name,
                'organizationName' => $this->user->organization?->name,
                'role' => $this->user->role->value,
                'inviteUrl' => $inviteUrl,
            ],
        );
    }
}
