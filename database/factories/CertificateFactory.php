<?php

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'organization_id' => fn (array $attributes) => Event::withoutGlobalScopes()->find($attributes['event_id'])->organization_id,
            'title' => 'Certificate of '.fake()->word(),
            'name_x' => 0.5,
            'name_y' => 0.5,
            'name_font_size' => 0.05,
            'font' => 'sans-serif',
            'color' => '#000000',
            'page_size' => 'a4',
            'orientation' => 'landscape',
            'status' => 'draft',
            'release_at' => now()->subDay(),
        ];
    }
}
