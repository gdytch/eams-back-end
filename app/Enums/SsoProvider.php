<?php

namespace App\Enums;

enum SsoProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
    case Facebook = 'facebook';
}
