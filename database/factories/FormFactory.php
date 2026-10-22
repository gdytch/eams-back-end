<?php

namespace Database\Factories;

use App\Enums\FormStatus;
use App\Models\Event;
use App\Models\Form;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'organization_id' => fn (array $attributes) => Event::withoutGlobalScopes()->find($attributes['event_id'])->organization_id,
            'title' => fake()->words(3, true),
            'description' => null,
            'is_required' => false,
            'status' => FormStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => FormStatus::Published]);
    }

    public function required(): static
    {
        return $this->state(['is_required' => true]);
    }
}
