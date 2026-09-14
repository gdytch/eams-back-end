<?php

namespace Database\Factories;

use App\Models\Attendee;
use App\Models\Organization;
use App\Services\ImageUploadService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\UploadedFile;

/**
 * @extends Factory<Attendee>
 */
class AttendeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'mobile_no' => fake()->optional(0.7)->phoneNumber(),
            'email_address' => fake()->optional(0.7)->safeEmail(),
            'remarks' => fake()->optional(0.3)->sentence(),
        ];
    }

    /**
     * Configure the factory to process avatar image after model creation
     */
    public function configure(): self
    {
        return $this->afterCreating(function (Attendee $attendee) {
            $this->processAvatarImage($attendee);
        });
    }

    /**
     * Process a random avatar from sample-avatars and create multiple sizes
     */
    private function processAvatarImage(Attendee $attendee): void
    {
        $avatarNumber = fake()->numberBetween(1, 100);
        $avatarFilename = "avatar{$avatarNumber}.jpg";
        $sampleAvatarPath = storage_path("sample-avatars/{$avatarFilename}");

        if (! file_exists($sampleAvatarPath)) {
            return;
        }

        // ImageUploadService only accepts UploadedFile|base64 string, not a raw path
        $file = new UploadedFile($sampleAvatarPath, $avatarFilename, 'image/jpeg', null, true);

        $paths = (new ImageUploadService)->process(
            $file,
            'profile_photo',
            "attendees/{$attendee->id}",
            'photo'
        );

        $attendee->update(['photo_paths' => $paths]);
    }
}
