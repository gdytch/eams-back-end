<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Speaker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Speaker>
 */
class SpeakerFactory extends Factory
{
    protected $model = Speaker::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => fake()->name(),
            'designation' => fake()->jobTitle(),
            'organization' => fake()->company(),
            'bio' => fake()->paragraph(),
            'photo_paths' => [
                'sm' => 'speakers/fake/photo-sm.webp',
                'md' => 'speakers/fake/photo-md.webp',
                'lg' => 'speakers/fake/photo-lg.webp',
                'original' => 'speakers/fake/photo-original.webp',
            ],
        ];
    }
}
