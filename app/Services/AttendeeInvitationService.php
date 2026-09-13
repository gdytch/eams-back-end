<?php

namespace App\Services;

use App\Mail\AttendeeAccountInviteMail;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class AttendeeInvitationService
{
    /**
     * Send an account invitation email if the attendee is eligible.
     * Eligibility: email present, no linked user, not already invited, inviter is staff, email not claimed.
     */
    public function sendIfEligible(Attendee $attendee, User $invitedBy): void
    {
        // Must have an email address
        if (blank($attendee->email_address)) {
            return;
        }

        // Must not already be linked to a user
        if ($attendee->user_id !== null) {
            return;
        }

        // Must not have been already invited
        if ($attendee->invited_at !== null) {
            return;
        }

        // Inviter must be staff (admin/checker), not an attendee
        if ($invitedBy->isAttendee()) {
            return;
        }

        // Email must not already belong to an existing user
        if (User::withoutGlobalScopes()->where('email', $attendee->email_address)->exists()) {
            return;
        }

        // Generate and persist the invite token
        $attendee->update([
            'invite_token' => Attendee::generateUniqueInviteToken(),
            'invited_at' => now(),
        ]);

        // Queue the invitation email
        Mail::to($attendee->email_address)->send(new AttendeeAccountInviteMail($attendee));

        // Record the audit log
        AuditLog::record('attendee.account_invited', $attendee, ['email' => $attendee->email_address]);
    }
}
