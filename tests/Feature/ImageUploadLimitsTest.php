<?php

namespace Tests\Feature;

use App\Rules\ValidImageUpload;
use App\Services\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ImageUploadLimitsTest extends TestCase
{
    public function test_accepts_base64_image_within_dimension_limits(): void
    {
        $image = UploadedFile::fake()->image('small.png', 8, 8)->get();
        $validator = Validator::make(['photo' => base64_encode($image)], ['photo' => new ValidImageUpload]);

        $this->assertFalse($validator->fails());
    }

    public function test_rejects_image_dimensions_before_image_decode(): void
    {
        $image = UploadedFile::fake()->image('small.png', 1, 1)->get();
        $image = substr_replace($image, pack('N', 20000).pack('N', 20000), 16, 8);
        $validator = Validator::make(['photo' => base64_encode($image)], ['photo' => new ValidImageUpload]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('photo', $validator->errors()->toArray());
    }

    public function test_rejects_oversized_base64_before_decoding(): void
    {
        Config::set('images.max_upload_bytes', 64);
        $encoded = str_repeat('A', 4 * (int) ceil(65 / 3));
        $validator = Validator::make(['photo' => $encoded], ['photo' => new ValidImageUpload(maxBytes: 32)]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('photo', $validator->errors()->toArray());
    }

    public function test_image_service_checks_dimensions_before_decoder(): void
    {
        Storage::fake('public');
        $image = UploadedFile::fake()->image('small.png', 1, 1)->get();
        $image = substr_replace($image, pack('N', 20000).pack('N', 20000), 16, 8);

        try {
            (new ImageUploadService)->process(base64_encode($image), 'profile_photo', 'test', 'photo');
            $this->fail('Image dimensions should be rejected before decoding.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('dimensions', $exception->getMessage());
        }

        Storage::disk('public')->assertMissing('test/photo-sm.webp');
    }
}
