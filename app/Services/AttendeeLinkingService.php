<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Applies the verified-email linking rule (ADR 0004).
 */
class AttendeeLinkingService
{
    /**
     * Link the user to every Attendee without a login that has the user's email, across all events,
     * skipping events where the user already has an Attendee. Does nothing until the email is verified.
     *
     * @return Collection<int, Attendee> The Attendees linked now.
     */
    public function linkVerifiedEmail(User $user): Collection
    {
        if (! $user->hasVerifiedEmail()) {
            return collect();
        }

        // Serialises concurrent links for one user so two requests cannot both claim the same event.
        User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->first();

        $takenEventIds = Attendee::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->pluck('event_id')
            ->all();

        $candidates = Attendee::withoutGlobalScopes()
            ->whereNull('merged_at')
            ->whereNull('user_id')
            ->whereNotNull('event_id')
            ->whereRaw('lower(email_address) = ?', [mb_strtolower($user->email)])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $linked = collect();

        foreach ($candidates as $attendee) {
            if (in_array($attendee->event_id, $takenEventIds, true)) {
                continue;
            }

            $takenEventIds[] = $attendee->event_id;

            $attendee->update(['user_id' => $user->id, 'invite_token' => null]);
            AuditLog::record('attendee.linked', $attendee, ['user_id' => $user->id]);

            $linked->push($attendee);
        }

        return $linked;
    }

    /**
     * Whether the token is a live Attendee invite for the email, which then counts as verified.
     */
    public function inviteVouchesForEmail(?string $token, string $email): bool
    {
        return $token !== null
            && Attendee::withoutGlobalScopes()
                ->where('invite_token', $token)
                ->where('invited_at', '>=', now()->subDays(config('auth.invite_expire')))
                ->whereNull('user_id')
                ->whereRaw('lower(email_address) = ?', [mb_strtolower($email)])
                ->exists();
    }

    /**
     * Whether another Attendee in the same event already holds the email.
     */
    public function emailTakenInEvent(string $email, Attendee $attendee): bool
    {
        return Attendee::withoutGlobalScopes()
            ->where('event_id', $attendee->event_id)
            ->whereKeyNot($attendee->id)
            ->whereRaw('lower(email_address) = ?', [mb_strtolower($email)])
            ->exists();
    }

    /**
     * Whether an Attendee without a login already holds the user's email in the event.
     */
    public function hasUnlinkedAttendeeIn(User $user, int $eventId): bool
    {
        return Attendee::withoutGlobalScopes()
            ->whereNull('merged_at')
            ->whereNull('user_id')
            ->where('event_id', $eventId)
            ->whereRaw('lower(email_address) = ?', [mb_strtolower($user->email)])
            ->exists();
    }
}
