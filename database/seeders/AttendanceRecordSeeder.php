<?php

namespace Database\Seeders;

use App\Enums\AttendanceMethod;
use App\Models\AttendanceRecord;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\User;
use Illuminate\Database\Seeder;

class AttendanceRecordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get a checker user to record attendance
        $checker = User::query()->whereHas('organization')->where('role', 'checker')->first();
        if (! $checker) {
            $this->command->warn('No checker user found. Skipping seeder.');

            return;
        }

        $sessions = EventSession::query()->with('event')->get();
        if ($sessions->isEmpty()) {
            $this->command->warn('No event sessions found. Skipping seeder.');

            return;
        }

        $registrations = EventRegistration::query()->get();
        if ($registrations->isEmpty()) {
            $this->command->warn('No event registrations found. Skipping seeder.');

            return;
        }

        $methods = [AttendanceMethod::Qr, AttendanceMethod::Manual];

        foreach ($sessions as $session) {
            // Get registrations for this event
            $eventRegistrations = $registrations->filter(fn($reg) => $reg->event_id === $session->event_id);

            if ($eventRegistrations->isEmpty()) {
                continue;
            }

            // Only simulate if session is in the past
            if ($session->endsAt()->isFuture()) {
                continue;
            }

            // Simulate 60-90% attendance rate
            $attendanceRate = rand(60, 90) / 100;
            $attendeesToCheck = $eventRegistrations->random((int) ($eventRegistrations->count() * $attendanceRate));

            $sessionStart = $session->startsAt();
            $sessionEnd = $session->startsAt()->copy()->addHours(2);

            foreach ($attendeesToCheck as $registration) {
                // Check-in: anywhere from 15 minutes before to session start
                $checkInTime = $sessionStart->copy()->subMinutes(rand(0, 15))->addMinutes(rand(0, 30));

                // 75% of attendees also check out
                $checkOutTime = null;
                if (rand(1, 100) <= 75) {
                    $checkOutTime = $checkInTime->copy()->addMinutes(rand(60, 240));
                    if ($checkOutTime->isAfter($sessionEnd)) {
                        $checkOutTime = $sessionEnd->copy()->subMinutes(rand(5, 30));
                    }
                }

                AttendanceRecord::create([
                    'event_registration_id' => $registration->id,
                    'event_session_id' => $session->id,
                    'check_in_at' => $checkInTime,
                    'check_out_at' => $checkOutTime,
                    'method' => $methods[array_rand($methods)],
                    'recorded_by' => $checker->id,
                ]);
            }
        }

        $this->command->info('Attendance records seeded successfully!');
    }
}
