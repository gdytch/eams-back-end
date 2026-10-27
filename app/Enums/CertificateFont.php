<?php

namespace App\Enums;

/** Built-in fonts for the attendee name. */
enum CertificateFont: string
{
    case Serif = 'serif';
    case SansSerif = 'sans-serif';
    case DejaVuSerif = 'dejavu-serif';
    case DejaVuSans = 'dejavu-sans';
    /** A cached Google Font; only selectable once the Certificate has one. */
    case Google = 'google';
}
