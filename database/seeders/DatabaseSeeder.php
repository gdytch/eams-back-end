<?php

namespace Database\Seeders;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
        ]);

        $organization = Organization::factory()->create([
            'name' => 'Sample Conference',
            'code' => 'SAMPLE',
        ]);

        $orgAdmin = User::factory()->orgAdmin()->for($organization)->create([
            'name' => 'Org Admin',
            'email' => 'admin@example.com',
        ]);

        $checker = User::factory()->checker()->for($organization)->create([
            'name' => 'Attendance Checker',
            'email' => 'checker@example.com',
        ]);

        $union = Union::factory()->for($organization)->create(['name' => 'Sample Union']);
        $mission = Mission::factory()->for($organization)->for($union)->create(['name' => 'Sample Mission']);

        $event = Event::factory()->for($organization)->create([
            'name' => 'Annual Convention 2027',
            'created_by' => $orgAdmin->id,
        ]);

        $morning = EventSession::factory()->for($event)->create([
            'name' => 'Morning Session',
            'session_date' => $event->start_date,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $afternoon = EventSession::factory()->for($event)->create([
            'name' => 'Afternoon Session',
            'session_date' => $event->start_date,
            'start_time' => '13:00:00',
            'end_time' => '17:00:00',
        ]);

        Attendee::factory()
            ->for($organization)
            ->for($union)
            ->for($mission)
            ->count(5)
            ->create(['created_by' => $checker->id])
            ->each(function (Attendee $attendee) use ($event, $checker) {
                EventRegistration::factory()->for($event)->for($attendee)->create([
                    'registered_by' => $checker->id,
                ]);
            });
    }
}
