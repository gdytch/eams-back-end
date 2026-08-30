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
        $event = Event::factory()->create();
        $attendee = Attendee::factory()->create();
        return [
            'event_id' => $event->id,
            'attendee_id' => $attendee->id,
            'qr_token' => EventRegistration::generateUniqueQrToken($event->id, $attendee->id),
            'registered_at' => now(),
        ];
    }
}
