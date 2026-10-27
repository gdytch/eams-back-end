<?php

namespace App\Enums;

enum CertificatePageSize: string
{
    case A4 = 'a4';
    case Short = 'short';
    case Long = 'long';

    /**
     * Portrait page size in millimetres as [width, height].
     *
     * @return array{float, float}
     */
    public function millimetres(): array
    {
        return match ($this) {
            self::A4 => [210.0, 297.0],
            self::Short => [215.9, 279.4],
            self::Long => [215.9, 330.2],
        };
    }
}
