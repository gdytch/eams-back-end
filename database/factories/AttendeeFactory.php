<?php

namespace Database\Factories;

use App\Enums\OrganizationLevel;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Union;
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
            // Pass `for($event)` to register the attendee in an event; the organization then follows the event (see Attendee::booted).
            'event_id' => null,
            'organization_id' => fn (array $attributes) => $attributes['event_id'] !== null
                ? Event::withoutGlobalScopes()->whereKey($attributes['event_id'])->value('organization_id')
                : Organization::factory(),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'mobile_no' => fake()->phoneNumber(),
            'email_address' => fake()->safeEmail(),
            // Fully registered by default; use draft() for an Attendee that never finished.
            'organization_level' => OrganizationLevel::Union,
            'union_id' => fn (array $attributes) => Union::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'registration_confirmed_at' => now(),
            'remarks' => fake()->optional(0.3)->sentence(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['registration_confirmed_at' => null]);
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
            'photo',
            disk: 'local'
        );

        $attendee->update(['photo_paths' => $paths]);
    }
}
