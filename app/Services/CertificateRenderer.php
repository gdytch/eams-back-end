<?php

namespace App\Services;

use App\Enums\CertificateFont;
use App\Enums\CertificateOrientation;
use App\Models\Attendee;
use App\Models\Certificate;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders one Issued certificate: the Certificate's Template scaled to cover the page and the Attendee's full
 * name centred on the stored point, shrunk to fit on one line. Certificate and Attendee in, PDF out.
 */
class CertificateRenderer
{
    /** The name may use this share of the width available around its centre point. */
    private const FIT_MARGIN = 0.95;

    /** The name never shrinks below this share of the requested size; wider than that is flagged. */
    public const MIN_SIZE_RATIO = 0.4;

    /** @throws CertificateRenderException */
    public function render(Certificate $certificate, Attendee $attendee): RenderedCertificate
    {
        $template = $this->template($certificate);
        $fontFile = null;

        try {
            [$width, $height] = $this->pageSize($certificate);
            $dompdf = $this->blankPage($width, $height);
            $metrics = $dompdf->getFontMetrics();
            $canvas = $dompdf->getCanvas();
            $font = $this->font($certificate, $metrics);

            $this->drawTemplate($canvas, $template, $width, $height);

            $name = trim(preg_replace('/\s+/', ' ', (string) $attendee->full_name));
            [$size, $tooLong] = $this->fit($metrics, $name, $font, $certificate->name_font_size * $width, $this->availableWidth($certificate, $width));
            $textWidth = $metrics->getTextWidth($name, $font, $size);
            $lineHeight = $metrics->getFontHeight($font, $size);
            $canvas->text(
                $certificate->name_x * $width - $textWidth / 2,
                $certificate->name_y * $height - $lineHeight / 2,
                $name,
                $font,
                $size,
                $this->color($certificate->color),
            );

            return new RenderedCertificate($dompdf->output(), $tooLong);
        } catch (CertificateRenderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CertificateRenderException('The certificate could not be rendered: '.$e->getMessage(), 0, $e);
        } finally {
            @unlink($template['file']);
        }
    }

    /** @return array{file: string, width: int, height: int} */
    private function template(Certificate $certificate): array
    {
        $disk = Storage::disk('public');
        if (! $certificate->template_path || ! $disk->exists($certificate->template_path)) {
            throw new CertificateRenderException('The Template image is missing.');
        }

        $bytes = $disk->get($certificate->template_path);
        $info = @getimagesizefromstring($bytes);
        $extension = match ($info[2] ?? null) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            default => throw new CertificateRenderException('The Template is not a PNG or JPG image.'),
        };

        $file = tempnam(sys_get_temp_dir(), 'cert-template-');
        rename($file, $file .= ".{$extension}");
        file_put_contents($file, $bytes);

        return ['file' => $file, 'width' => $info[0], 'height' => $info[1]];
    }

    /** @return array{float, float} Page width and height in points. */
    private function pageSize(Certificate $certificate): array
    {
        [$width, $height] = array_map(fn (float $mm) => $mm / 25.4 * 72, $certificate->page_size->millimetres());

        return $certificate->orientation === CertificateOrientation::Landscape ? [$height, $width] : [$width, $height];
    }

    private function blankPage(float $width, float $height): Dompdf
    {
        $directory = storage_path('app/certificate-fonts');
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $options = new Options;
        $options->setFontDir($directory);
        $options->setFontCache($directory);
        $options->setChroot([base_path(), storage_path(), sys_get_temp_dir(), $directory]);
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper([0, 0, $width, $height]);
        $dompdf->loadHtml('<html><body></body></html>');
        $dompdf->render();

        return $dompdf;
    }

    private function font(Certificate $certificate, FontMetrics $metrics): string
    {
        if ($certificate->font === CertificateFont::Google) {
            return $this->googleFont($certificate, $metrics);
        }

        return $metrics->getFont($certificate->font->dompdfFamily() ?? 'helvetica', 'normal');
    }

    private function googleFont(Certificate $certificate, FontMetrics $metrics): string
    {
        $disk = Storage::disk('local');
        if (! $certificate->google_font_path || ! $disk->exists($certificate->google_font_path)) {
            throw new CertificateRenderException('The Google font file is missing.');
        }

        $family = 'gf-'.md5($certificate->google_font_path);
        $registered = $metrics->registerFont(['family' => $family, 'style' => 'normal', 'weight' => 'normal'], $disk->path($certificate->google_font_path));
        $font = $registered ? $metrics->getFont($family, 'normal') : null;
        if ($font === null) {
            throw new CertificateRenderException('The Google font could not be loaded.');
        }

        return $font;
    }

    /**
     * The template covers the page: scaled up to the larger ratio, centred, the overflow clipped.
     *
     * @param  array{file: string, width: int, height: int}  $template
     */
    private function drawTemplate(Canvas $canvas, array $template, float $width, float $height): void
    {
        $scale = max($width / $template['width'], $height / $template['height']);
        $drawWidth = $template['width'] * $scale;
        $drawHeight = $template['height'] * $scale;

        $canvas->clipping_rectangle(0, 0, $width, $height);
        $canvas->image($template['file'], ($width - $drawWidth) / 2, ($height - $drawHeight) / 2, $drawWidth, $drawHeight);
        $canvas->clipping_end();
    }

    /** Width from the name's centre to the nearest page edge, both ways, with a margin. */
    private function availableWidth(Certificate $certificate, float $width): float
    {
        return 2 * min($certificate->name_x, 1 - $certificate->name_x) * $width * self::FIT_MARGIN;
    }

    /** @return array{float, bool} The size to draw at, and whether the name is too long even at the minimum. */
    private function fit(FontMetrics $metrics, string $name, string $font, float $requested, float $available): array
    {
        $textWidth = $metrics->getTextWidth($name, $font, $requested);
        if ($textWidth <= $available || $textWidth <= 0) {
            return [$requested, false];
        }

        $floor = $requested * self::MIN_SIZE_RATIO;
        $size = $requested * $available / $textWidth;
        if ($size >= $floor) {
            return [$size * 0.995, false];
        }

        return [$floor, true];
    }

    /** @return array{float, float, float} */
    private function color(string $hex): array
    {
        return [
            hexdec(substr($hex, 1, 2)) / 255,
            hexdec(substr($hex, 3, 2)) / 255,
            hexdec(substr($hex, 5, 2)) / 255,
        ];
    }
}
