<?php

namespace Database\Factories;

use App\Models\Church;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Church>
 */
class ChurchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'union_id' => Union::factory(),
            'mission_id' => Mission::factory(),
            'name' => fake()->unique()->city().' Church',
            'address' => fake()->address(),
        ];
    }
}
