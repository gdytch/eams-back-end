<?php

namespace App\Enums;

enum IssuedCertificateStatus: string
{
    case Pending = 'pending';
    case Generated = 'generated';
    case Failed = 'failed';
}
