<?php

namespace App\Services;

use App\Mail\AttendeeRegistrationWelcomeMail;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class AttendeeInvitationService
{
    /**
     * Queue the welcome mail for an Attendee's first full registration (the model calls this once,
     * when first_registered_at is set). An Attendee with an email and no login (admin-registered) gets
     * one merged mail: the welcome content plus the account invite link. Without an email, no mail.
     */
    public function sendWelcome(Attendee $attendee): void
    {
        $email = $attendee->email_address ?? $attendee->user?->email;

        if (blank($email)) {
            return;
        }

        $invite = $attendee->user_id === null
            && ! User::withoutGlobalScopes()->where('email', $email)->exists();

        if ($invite) {
            $attendee->update([
                'invite_token' => Attendee::generateUniqueInviteToken(),
                'invited_at' => now(),
            ]);

            AuditLog::record('attendee.account_invited', $attendee, ['email' => $email]);
        }

        Mail::to($email)->send(new AttendeeRegistrationWelcomeMail($attendee));
    }
}
