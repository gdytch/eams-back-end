<?php

namespace Database\Factories;

use App\Enums\IssuedCertificateStatus;
use App\Models\Attendee;
use App\Models\Certificate;
use App\Models\IssuedCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssuedCertificate>
 */
class IssuedCertificateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'certificate_id' => Certificate::factory(),
            'attendee_id' => fn (array $attributes) => Attendee::factory()->for(
                Certificate::withoutGlobalScopes()->find($attributes['certificate_id'])->event,
            ),
            'status' => IssuedCertificateStatus::Pending,
        ];
    }

    /** A generated row whose file is stored at `$path`. */
    public function generated(string $path = 'certificate.pdf'): static
    {
        return $this->state(fn () => [
            'status' => IssuedCertificateStatus::Generated,
            'file_path' => $path,
            'generated_at' => now(),
        ]);
    }
}
