<?php

namespace App\Enums;

enum CertificateReleaseRule: string
{
    case Everyone = 'everyone';
    case CheckedIn = 'checked_in';
    case Rate50 = 'rate_50';
    case Rate75 = 'rate_75';

    /** The minimum Attendance rate in percent; null for rules that do not use it. */
    public function percent(): ?int
    {
        return match ($this) {
            self::Rate50 => 50,
            self::Rate75 => 75,
            default => null,
        };
    }
}
