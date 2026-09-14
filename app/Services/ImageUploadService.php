<?php

namespace App\Services;

use App\Support\ImageInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use InvalidArgumentException;

class ImageUploadService
{
    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = ImageManager::gd();
    }

    /**
     * Process an image upload: decode, resize to preset dimensions, encode to WebP, store, and return paths.
     *
     * @param  UploadedFile|string  $input  Uploaded file or base64 string
     * @param  string  $preset  Configuration preset key (e.g., 'profile_photo', 'event_banner')
     * @param  string  $directory  Storage directory (e.g., 'events/1', 'users/2', 'attendees/3')
     * @param  string  $prefix  Filename prefix (e.g., 'banner', 'photo')
     * @return array<string, string> Associative array keyed by the preset's size keys (e.g. ['sm' => path, 'md' => path, 'lg' => path])
     *
     * @throws InvalidArgumentException
     */
    public function process(
        UploadedFile|string $input,
        string $preset,
        string $directory,
        string $prefix,
    ): array {
        $presets = config('images.presets');

        if (! isset($presets[$preset])) {
            throw new InvalidArgumentException("Unknown image preset: {$preset}");
        }

        $sizes = $presets[$preset];
        $quality = config('images.quality');

        // Decode input to binary
        $binary = ImageInput::toBinary($input);

        // Read and process image
        $image = $this->manager->read($binary);

        $paths = [];

        foreach ($sizes as $sizeKey => $maxWidth) {
            // Scale down to fit within maxWidth (never upscale), preserve aspect ratio
            if ($image->width() > $maxWidth) {
                $image->scaleDown(width: $maxWidth);
            }

            // Encode to WebP at configured quality
            $encoded = $image->toWebp(quality: $quality)->toString();

            // Store: fixed filename so re-upload overwrites naturally
            $filename = "{$prefix}-{$sizeKey}.webp";
            $path = "{$directory}/{$filename}";

            Storage::disk('public')->put($path, $encoded);

            $paths[$sizeKey] = $path;

            // Re-read the original for the next size iteration to avoid cumulative scaling
            $image = $this->manager->read($binary);
        }

        return $paths;
    }
}
