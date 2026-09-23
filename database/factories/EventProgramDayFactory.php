<?php

namespace Database\Factories;

use App\Models\EventProgram;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventProgramDayFactory extends Factory
{
    public function definition(): array
    {
        return ['event_program_id' => EventProgram::factory(), 'title' => fake()->words(3, true), 'date' => fake()->date(), 'sort_order' => 0];
    }
}
