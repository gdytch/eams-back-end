<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class ImageInput
{
    /**
     * Convert an uploaded file or base64 string to binary data.
     *
     * @throws InvalidArgumentException on invalid input or failed decode
     */
    public static function toBinary(UploadedFile|string $input, ?int $maxUploadBytes = null): string
    {
        $maxBytes = min(
            (int) config('images.max_upload_bytes', 10 * 1024 * 1024),
            $maxUploadBytes ?? PHP_INT_MAX,
        );

        if ($input instanceof UploadedFile) {
            if ($input->getSize() > $maxBytes) {
                throw new InvalidArgumentException('Image exceeds the maximum upload size.');
            }

            $binary = file_get_contents($input->getRealPath());

            if ($binary === false || strlen($binary) > $maxBytes) {
                throw new InvalidArgumentException('Image exceeds the maximum upload size or could not be read.');
            }

            return $binary;
        }

        $input = (string) $input;

        // Strip data URI prefix if present: data:image/png;base64,... → ...
        if (str_starts_with($input, 'data:')) {
            if (! str_contains($input, ',')) {
                throw new InvalidArgumentException('Invalid data URI format.');
            }
            $input = substr($input, strpos($input, ',') + 1);
        }

        if (strlen($input) > 4 * (int) ceil($maxBytes / 3)) {
            throw new InvalidArgumentException('Image exceeds the maximum upload size.');
        }

        $decoded = base64_decode($input, strict: true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Failed to decode base64 string.');
        }

        if (strlen($decoded) > $maxBytes) {
            throw new InvalidArgumentException('Image exceeds the maximum upload size.');
        }

        return $decoded;
    }

    /**
     * Validate image dimensions from the header before allocating a decoded image buffer.
     *
     * @throws InvalidArgumentException
     */
    public static function assertDimensions(string $binary): void
    {
        $dimensions = @getimagesizefromstring($binary);

        if ($dimensions === false) {
            throw new InvalidArgumentException('The image dimensions could not be read.');
        }

        [$width, $height] = $dimensions;
        $maxWidth = (int) config('images.max_width', 8192);
        $maxHeight = (int) config('images.max_height', 8192);
        $maxPixels = (int) config('images.max_pixels', 12000000);

        if ($width < 1 || $height < 1 || $width > $maxWidth || $height > $maxHeight || $width * $height > $maxPixels) {
            throw new InvalidArgumentException('The image dimensions exceed the allowed limits.');
        }
    }
}
