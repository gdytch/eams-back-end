<?php

namespace Database\Seeders;

use App\Models\Attendee;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Carbon\Carbon;
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
            'name' => 'Southeastern Philippine Union Mission',
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

        $union = Union::factory()->for($organization)->create(['name' => 'Southeastern Philippine Union Mission', 'code' => 'SEPUM']);
        $missions = [
            Mission::factory()->for($organization)->for($union)->create(['name' => 'Davao Mission', 'code' => 'DM']),
            Mission::factory()->for($organization)->for($union)->create(['name' => 'Northern Davao Mission', 'code' => 'NDM']),
            Mission::factory()->for($organization)->for($union)->create(['name' => 'Southern Mindanao Mission', 'code' => 'SMM']),
        ];

        // Create churches under each mission
        $churches = [];
        foreach ($missions as $mission) {
            $churches[$mission->id] = [
                Church::factory()->for($organization)->for($union)->for($mission)->create(['name' => 'Central '.$mission->name.' Church']),
                Church::factory()->for($organization)->for($union)->for($mission)->create(['name' => 'District '.$mission->name.' Church']),
            ];
        }

        $event = Event::factory()->for($organization)->create([
            'name' => 'Annual Convention 2027',
            'created_by' => $orgAdmin->id,
        ]);

        $startDate = Carbon::parse($event->start_date);
        $endDate = Carbon::parse($event->end_date);
        $day = 1;
        while (! $startDate->isAfter($endDate)) {
            EventSession::factory()->for($event)->create([
                'name' => "Day {$day} Morning Session",
                'session_date' => $startDate->toDateString(),
                'start_time' => '08:00:00',
                'end_time' => '12:00:00',
            ]);

            EventSession::factory()->for($event)->create([
                'name' => "Day {$day} Afternoon Session",
                'session_date' => $startDate->toDateString(),
                'start_time' => '13:00:00',
                'end_time' => '17:00:00',
            ]);
            $startDate->addDay();
            $day++;
        }

        Attendee::factory()
            ->recycle($organization)
            ->count(350)
            ->create(['created_by' => $checker->id])
            ->each(function (Attendee $attendee, int $index) use ($missions, $churches, $union, $event, $checker) {
                $mission = $missions[$index % count($missions)];
                $missionChurches = $churches[$mission->id];
                $church = $missionChurches[$index % count($missionChurches)];

                if ($index < 50) {
                    $organizationLevel = 'union';
                } else {
                    $organizationLevel = 'mission';
                }
                $attendee->update([
                    'union_id' => $union->id,
                    'mission_id' => $mission->id,
                    'organization_level' => $organizationLevel,
                    'church_id' => $church->id,
                    'mobile_no' => fake()->phoneNumber(),
                    'email_address' => fake()->unique()->safeEmail(),
                    'remarks' => fake()->optional(0.7)->sentence(),
                ]);

                EventRegistration::factory()->for($event)->for($attendee)->create([
                    'registered_by' => $checker->id,
                ]);
            });

        $this->call(AttendanceRecordSeeder::class);
    }
}
