<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventProgram;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventProgram>
 */
class EventProgramFactory extends Factory
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
        ];
    }
}
