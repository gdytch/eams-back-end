<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSession>
 */
class EventSessionFactory extends Factory
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
            'name' => fake()->randomElement(['Morning Session', 'Afternoon Session', 'Closing Session']),
            'description' => fake()->optional()->sentence(),
            'session_date' => now()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ];
    }
}
