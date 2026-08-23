<?php

namespace Database\Factories;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventRegistration>
 */
class EventRegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'attendee_id' => Attendee::factory(),
            'qr_token' => EventRegistration::generateUniqueQrToken(),
            'registered_at' => now(),
        ];
    }
}
