<?php

namespace App\Enums;

enum CertificateStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
