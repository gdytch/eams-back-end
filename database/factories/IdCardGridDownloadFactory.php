<?php

namespace Database\Factories;

use App\Enums\IdCardGridDownloadStatus;
use App\Models\Event;
use App\Models\IdCardGridDownload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdCardGridDownload>
 */
class IdCardGridDownloadFactory extends Factory
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
            'requested_by' => User::factory(),
            'registration_ids' => null,
            'status' => IdCardGridDownloadStatus::Pending,
            'progress_percentage' => 0,
            'total_batches' => 0,
            'completed_batches' => 0,
            'file_path' => null,
            'failure_reason' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => IdCardGridDownloadStatus::Completed,
                'progress_percentage' => 100,
                'file_path' => 'id-card-grids/test.pdf',
                'completed_at' => now(),
            ];
        });
    }

    public function failed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => IdCardGridDownloadStatus::Failed,
                'progress_percentage' => 0,
                'failure_reason' => 'No ID cards were ready to include in this batch.',
                'completed_at' => now(),
            ];
        });
    }
}
