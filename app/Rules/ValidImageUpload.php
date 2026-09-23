<?php

namespace App\Rules;

use App\Support\ImageInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;

class ValidImageUpload implements ValidationRule
{
    private const MAX_DECODED_SIZE = 10 * 1024 * 1024; // 10 MB

    private const ALLOWED_MIMES = ['image/png', 'image/jpeg'];

    /** @var array<int, string> */
    private array $allowedMimes;

    /** @param array<int, string>|null $allowedMimes */
    public function __construct(?array $allowedMimes = null)
    {
        $this->allowedMimes = $allowedMimes ?? self::ALLOWED_MIMES;
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

        try {
            $binary = ImageInput::toBinary($value);
        } catch (InvalidArgumentException $e) {
            $fail('The '.$attribute.' must be a valid base64-encoded image or file.');

            return;
        }

        // Check decoded size ≤ 10 MB
        if (strlen($binary) > self::MAX_DECODED_SIZE) {
            $fail('The '.$attribute.' may not be greater than 10 MB.');

            return;
        }

        // Detect MIME type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $binary);
        finfo_close($finfo);

        if (! in_array($mime, $this->allowedMimes, true)) {
            $fail('The '.$attribute.' must be a '.($this->allowedMimes === ['image/png'] ? 'PNG' : 'PNG or JPEG').' image.');

            return;
        }
    }
}
