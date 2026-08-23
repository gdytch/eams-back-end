<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Union;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Union>
 */
class UnionFactory extends Factory
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
            'name' => fake()->unique()->city().' Union',
            'code' => fake()->unique()->lexify('UN-????'),
        ];
    }
}
