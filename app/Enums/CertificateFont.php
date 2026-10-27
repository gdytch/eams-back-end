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

    /** The Dompdf font family of a built-in font; null for a Google font, which is registered from its file. */
    public function dompdfFamily(): ?string
    {
        return match ($this) {
            self::Serif => 'times',
            self::SansSerif => 'helvetica',
            self::DejaVuSerif => 'dejavu serif',
            self::DejaVuSans => 'dejavu sans',
            self::Google => null,
        };
    }
}
