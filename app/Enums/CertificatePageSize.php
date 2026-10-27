<?php

namespace App\Enums;

enum CertificatePageSize: string
{
    case A4 = 'a4';
    case Short = 'short';
    case Long = 'long';

    public const MAX_DPI = 300;

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

    /**
     * Largest Template in pixels, as [width, height], that does not exceed 300 dpi on this page.
     *
     * @return array{int, int}
     */
    public function maxPixels(CertificateOrientation $orientation): array
    {
        [$width, $height] = array_map(fn (float $mm) => (int) round($mm / 25.4 * self::MAX_DPI), $this->millimetres());

        return $orientation === CertificateOrientation::Portrait ? [$width, $height] : [$height, $width];
    }
}
