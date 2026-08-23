<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('now', '+2 months');

        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'start_date' => $startDate,
            'end_date' => $startDate,
            'venue' => fake()->address(),
            'status' => EventStatus::Published,
            'requires_check_out' => false,
            'check_in_window_minutes' => 30,
        ];
    }
}
