<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case OrgAdmin = 'org_admin';
    case Checker = 'checker';
}
