<?php

namespace App\Policies;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\User;
use App\Policies\Concerns\DeniesArchivedEvents;
use Illuminate\Auth\Access\Response;

/**
 * An Attendee is one person's registration in one event, so event-scoped rules
 * (access, archived writes) apply to every ability.
 */
class AttendeePolicy
{
    use DeniesArchivedEvents;

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin() || $user->isChecker();
    }

    public function view(User $user, Attendee $attendee): bool
    {
        if ($user->isAttendee()) {
            return $attendee->user_id === $user->id;
        }

        return ($user->isSuperAdmin() || $user->organization_id === $attendee->organization_id)
            && $this->hasEventAccess($user, $attendee);
    }

    /**
     * Without an event: may this role create attendees at all. With an event: may the user register people in it.
     */
    public function create(User $user, ?Event $event = null): Response|bool
    {
        $isStaff = $user->isSuperAdmin() || $user->isOrgAdmin() || $user->isChecker();

        if ($event === null) {
            return $isStaff;
        }

        return $this->allowWrite(
            $isStaff
                && ($user->isSuperAdmin() || $user->organization_id === $event->organization_id)
                && $user->hasAccessToEvent($event),
            $event,
        );
    }

    /**
     * Denied when the attendee's own event is archived.
     */
    public function update(User $user, Attendee $attendee): Response|bool
    {
        $allowed = $user->isAttendee()
            ? $attendee->user_id === $user->id
            : $user->isSuperAdmin() || $user->organization_id === $attendee->organization_id;

        return $this->allowWriteForAttendee($allowed, $attendee);
    }

    /**
     * Denied when the attendee's event is archived, since deleting would destroy its history.
     */
    public function delete(User $user, Attendee $attendee): Response|bool
    {
        return $this->allowWriteForAttendee(
            $user->isSuperAdmin() || ($user->isOrgAdmin() && $user->organization_id === $attendee->organization_id),
            $attendee,
        );
    }

    /**
     * Admin wizard actions on an existing Attendee (finish, resend invite): admins only, never checkers.
     */
    public function registerOnBehalf(User $user, Attendee $attendee): Response|bool
    {
        return $this->delete($user, $attendee);
    }

    /**
     * Merging as primary or as duplicate source, denied when the attendee's event is archived.
     */
    public function merge(User $user, Attendee $attendee): Response|bool
    {
        return $this->delete($user, $attendee);
    }

    public function downloadIdCard(User $user, Attendee $attendee): bool
    {
        return $this->view($user, $attendee);
    }

    public function viewIdCardImage(User $user, Attendee $attendee): bool
    {
        return $this->view($user, $attendee);
    }

    /**
     * Only used by ID card regenerate, so it is a write.
     */
    public function manageIdCards(User $user, Attendee $attendee): Response|bool
    {
        $event = $this->eventOf($attendee);

        return $this->allowWriteForAttendee(
            $user->isSuperAdmin()
                || ($user->isOrgAdmin() && $user->organization_id === $attendee->organization_id && ($event === null || $user->hasAccessToEvent($event))),
            $attendee,
        );
    }

    public function viewStaffData(User $user, Attendee $attendee): bool
    {
        $event = $this->eventOf($attendee);

        return $event !== null && $user->can('viewStaffData', $event);
    }

    private function hasEventAccess(User $user, Attendee $attendee): bool
    {
        $event = $this->eventOf($attendee);

        return $event === null || $user->hasAccessToEvent($event);
    }

    private function allowWriteForAttendee(bool $allowed, Attendee $attendee): Response|bool
    {
        $event = $this->eventOf($attendee);

        return $event === null ? $allowed : $this->allowWrite($allowed, $event);
    }

    private function eventOf(Attendee $attendee): ?Event
    {
        if ($attendee->event_id === null) {
            return null;
        }

        return $attendee->relationLoaded('event')
            ? $attendee->event
            : Event::withoutGlobalScopes()->find($attendee->event_id);
    }
}
