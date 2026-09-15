<?php

namespace Database\Factories;

use App\Models\EventProgram;
use App\Models\EventProgramItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventProgramItem>
 */
class EventProgramItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_program_id' => EventProgram::factory(),
            'order' => 0,
            'date' => now()->toDateString(),
            'start_time' => fake()->time('H:i'),
            'end_time' => fake()->time('H:i'),
            'part_title' => fake()->optional()->sentence(3),
            'part_subtitle' => fake()->optional()->sentence(2),
            'part_description' => fake()->optional()->paragraph(),
            'participant_name' => fake()->optional()->name(),
            'participant_description' => fake()->optional()->sentence(),
            'photo_paths' => null,
            'part_remarks' => fake()->optional()->sentence(),
        ];
    }
}
