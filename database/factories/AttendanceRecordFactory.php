<?php

namespace Database\Factories;

use App\Enums\AttendanceMethod;
use App\Models\AttendanceRecord;
use App\Models\EventRegistration;
use App\Models\EventSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_registration_id' => EventRegistration::factory(),
            'event_session_id' => EventSession::factory(),
            'check_in_at' => now(),
            'method' => AttendanceMethod::Qr,
        ];
    }
}
