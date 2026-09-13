<?php

namespace App\Enums;

enum IdCardGridDownloadStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
