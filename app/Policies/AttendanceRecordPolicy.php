<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Policies\Concerns\DeniesArchivedEvents;
use Illuminate\Auth\Access\Response;

class AttendanceRecordPolicy
{
    use DeniesArchivedEvents;

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin() || $user->isChecker();
    }

    public function view(User $user, AttendanceRecord $attendanceRecord): bool
    {
        $event = $attendanceRecord->eventRegistration->event;

        return $user->can('viewStaffData', $event);
    }

    public function checkOut(User $user, AttendanceRecord $attendanceRecord): Response|bool
    {
        /** @var EventRegistration $registration */
        $registration = $attendanceRecord->eventRegistration;
        /** @var Event $event */
        $event = $registration->event;

        return $this->allowWrite($this->view($user, $attendanceRecord), $event);
    }

    public function create(User $user, Event $event): Response|bool
    {
        return $this->allowWrite(
            ($user->isSuperAdmin() || $user->isOrgAdmin() || $user->isChecker())
                && ($user->isSuperAdmin() || $user->organization_id === $event->organization_id)
                && $user->hasAccessToEvent($event),
            $event,
        );
    }
}
