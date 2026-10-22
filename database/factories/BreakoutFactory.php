<?php

namespace Database\Factories;

use App\Models\Breakout;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Breakout>
 */
class BreakoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'organization_id' => fn (array $attributes) => Event::withoutGlobalScopes()->find($attributes['event_id'])->organization_id,
            'title' => fake()->sentence(3),
            'date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'room' => 'Room '.fake()->numberBetween(1, 9),
            'capacity' => null,
            'topics' => [fake()->words(2, true)],
        ];
    }
}
