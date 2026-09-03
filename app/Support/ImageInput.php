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
    public static function toBinary(UploadedFile|string $input): string
    {
        if ($input instanceof UploadedFile) {
            return file_get_contents($input->getRealPath());
        }

        $input = (string) $input;

        // Strip data URI prefix if present: data:image/png;base64,... → ...
        if (str_starts_with($input, 'data:')) {
            if (! str_contains($input, ',')) {
                throw new InvalidArgumentException('Invalid data URI format.');
            }
            $input = substr($input, strpos($input, ',') + 1);
        }

        $decoded = base64_decode($input, strict: true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Failed to decode base64 string.');
        }

        return $decoded;
    }
}
