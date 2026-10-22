<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Upcoming = 'upcoming';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
