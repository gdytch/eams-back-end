<?php

namespace Database\Factories;

use App\Models\EventProgramDay;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventProgramSectionFactory extends Factory
{
    public function definition(): array
    {
        return ['event_program_day_id' => EventProgramDay::factory(), 'title' => fake()->words(3, true),  'sort_order' => 0];
    }
}
