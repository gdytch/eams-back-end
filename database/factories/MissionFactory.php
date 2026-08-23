<?php

namespace Database\Factories;

use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mission>
 */
class MissionFactory extends Factory
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
            'name' => fake()->unique()->city().' Mission',
            'code' => fake()->unique()->lexify('MI-????'),
        ];
    }
}
