<?php

namespace App\Enums;

/** Built-in fonts for the attendee name. */
enum CertificateFont: string
{
    case Serif = 'serif';
    case SansSerif = 'sans-serif';
    case DejaVuSerif = 'dejavu-serif';
    case DejaVuSans = 'dejavu-sans';
}
