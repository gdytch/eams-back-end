<?php

namespace App\Rules;

use App\Support\ImageInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;

class ValidImageUpload implements ValidationRule
{
    private const ALLOWED_MIMES = ['image/png', 'image/jpeg'];

    /** @var array<int, string> */
    private array $allowedMimes;

    private ?int $maxBytes;

    /** @param array<int, string>|null $allowedMimes */
    public function __construct(?array $allowedMimes = null, ?int $maxBytes = null)
    {
        $this->allowedMimes = $allowedMimes ?? self::ALLOWED_MIMES;
        $this->maxBytes = $maxBytes;
    }

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  \Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate($attribute, $value, $fail): void
    {
        if (! ($value instanceof UploadedFile) && ! is_string($value)) {
            $fail('The '.$attribute.' must be a file or base64 string.');

            return;
        }

        if ($value instanceof UploadedFile && $this->maxBytes !== null && $value->getSize() > $this->maxBytes) {
            $fail('The '.$attribute.' must not be larger than '.round($this->maxBytes / 1048576, 1).' MB.');

            return;
        }

        try {
            $binary = ImageInput::toBinary($value, $this->maxBytes);
        } catch (InvalidArgumentException $e) {
            $fail('The '.$attribute.' must be a valid base64-encoded image or file.');

            return;
        }

        // Detect MIME type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $binary);
        finfo_close($finfo);

        if (! in_array($mime, $this->allowedMimes, true)) {
            $fail('The '.$attribute.' must be '.$this->describeAllowed().'.');

            return;
        }

        // A PDF has no raster dimensions to check.
        if ($mime === 'application/pdf') {
            return;
        }

        try {
            ImageInput::assertDimensions($binary);
        } catch (InvalidArgumentException) {
            $fail('The '.$attribute.' has invalid or unsupported image dimensions.');
        }
    }

    private function describeAllowed(): string
    {
        if (in_array('application/pdf', $this->allowedMimes, true)) {
            return 'a PNG, JPEG or PDF file';
        }

        return $this->allowedMimes === ['image/png'] ? 'a PNG image' : 'a PNG or JPEG image';
    }
}
