<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Policies\Concerns\DeniesArchivedEvents;
use Illuminate\Auth\Access\Response;

class EventRegistrationPolicy
{
    use DeniesArchivedEvents;

    public function viewAny(User $user, Event $event): bool
    {
        return $user->can('viewStaffData', $event);
    }

    public function view(User $user, EventRegistration $eventRegistration): Response|bool
    {
        if ($user->isAttendee()) {
            return $eventRegistration->attendee?->user_id === $user->id;
        }

        if ($eventRegistration->event->isHiddenFrom($user)) {
            return Response::denyAsNotFound();
        }

        return ($user->isSuperAdmin() || $user->organization_id === $eventRegistration->event->organization_id)
            && $user->hasAccessToEvent($eventRegistration->event);
    }

    public function create(User $user, Event $event): Response|bool
    {
        return $this->allowWrite(
            ($user->isSuperAdmin() || ($user->isOrgAdmin() || $user->isChecker()) && $user->organization_id === $event->organization_id)
                && $user->hasAccessToEvent($event),
            $event,
        );
    }

    public function update(User $user, EventRegistration $eventRegistration): Response|bool
    {
        return $this->delete($user, $eventRegistration);
    }

    public function delete(User $user, EventRegistration $eventRegistration): Response|bool
    {
        return $this->allowWrite(
            $user->isSuperAdmin() || ($user->isOrgAdmin() && $user->organization_id === $eventRegistration->event->organization_id),
            $eventRegistration->event,
        );
    }

    public function downloadIdCard(User $user, EventRegistration $eventRegistration): Response|bool
    {
        if ($eventRegistration->event->isHiddenFrom($user)) {
            return Response::denyAsNotFound();
        }

        if ($user->isAttendee()) {
            return $eventRegistration->attendee?->user_id === $user->id;
        }

        return ($user->isSuperAdmin() || $user->organization_id === $eventRegistration->event->organization_id)
            && $user->hasAccessToEvent($eventRegistration->event);
    }

    public function viewIdCardImage(User $user, EventRegistration $eventRegistration): Response|bool
    {
        if ($eventRegistration->event->isHiddenFrom($user)) {
            return Response::denyAsNotFound();
        }

        if ($user->isAttendee()) {
            return $eventRegistration->attendee?->user_id === $user->id;
        }

        return ($user->isSuperAdmin() || $user->organization_id === $eventRegistration->event->organization_id)
            && $user->hasAccessToEvent($eventRegistration->event);
    }

    /**
     * Only used by ID card regenerate, so it is a write.
     */
    public function manageIdCards(User $user, EventRegistration $eventRegistration): Response|bool
    {
        /** @var Event $event */
        $event = $eventRegistration->event;

        return $this->allowWrite(
            $user->isSuperAdmin()
                || ($user->isOrgAdmin() && $user->organization_id === $event->organization_id && $user->hasAccessToEvent($event)),
            $event,
        );
    }

    public function viewStaffData(User $user, EventRegistration $eventRegistration): bool
    {
        return $user->can('viewStaffData', $eventRegistration->event);
    }
}
