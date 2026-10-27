<?php

namespace App\Enums;

enum CertificateReleaseRule: string
{
    case Everyone = 'everyone';
    case CheckedIn = 'checked_in';
    case Rate50 = 'rate_50';
    case Rate75 = 'rate_75';
}
