<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\Event;
use App\Models\User;

class AttendanceRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin() || $user->isChecker();
    }

    public function view(User $user, AttendanceRecord $attendanceRecord): bool
    {
        $event = $attendanceRecord->eventRegistration->event;

        return ($user->isSuperAdmin() || $user->organization_id === $event->organization_id)
            && $user->hasAccessToEvent($event);
    }

    public function create(User $user, Event $event): bool
    {
        return ($user->isSuperAdmin() || $user->organization_id === $event->organization_id)
            && $user->hasAccessToEvent($event);
    }
}
