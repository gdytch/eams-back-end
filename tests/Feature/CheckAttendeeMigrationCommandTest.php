<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Runs app:check-attendee-migration against data seeded into the pre-split
 * schema, before and after the real split and clean-up migrations.
 */
class CheckAttendeeMigrationCommandTest extends TestCase
{
    private const BASELINE = 'exports/attendee-migration-baseline.json';

    private Migration $split;

    private Migration $cleanup;

    private int $orgId;

    /** @var array<string, int> */
    private array $events = [];

    /** @var array<string, int> */
    private array $attendees = [];

    /** @var array<string, int> */
    private array $registrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        // No RefreshDatabase transaction: SQLite ignores PRAGMA foreign_keys inside one.
        $this->artisan('migrate:fresh');
        Storage::fake('local');
        Storage::fake('public');

        $this->split = require base_path('database/migrations/2026_10_22_000000_split_attendees_per_event.php');
        $this->cleanup = require base_path('database/migrations/2026_10_22_000001_clean_up_attendees_after_split.php');
        $this->split->down();
        $this->seedPreSplitData();
    }

    public function test_before_the_migration_it_reports_every_count(): void
    {
        $this->artisan('app:check-attendee-migration')
            ->expectsOutputToContain('Registrations (become Attendee rows)')
            ->expectsOutputToContain('Attendees with no registration')
            ->expectsOutputToContain('Merged sources (deleted)')
            ->expectsOutputToContain('Dismissal pairs kept')
            ->expectsOutputToContain('Speaker links nulled')
            ->expectsOutputToContain('All checks passed.')
            ->assertExitCode(0);
    }

    public function test_before_the_migration_the_counts_match_the_seed_data(): void
    {
        $this->artisan('app:check-attendee-migration')->run();

        $baseline = json_decode(Storage::disk('local')->get(self::BASELINE), true);
        $this->assertSame(4, $baseline['registrations']);
        $this->assertSame(1, $baseline['attendance_records']);

        $this->artisan('app:check-attendee-migration')
            ->expectsOutputToContain('1 of 3, as 2 per-event row(s)')
            ->expectsOutputToContain('1 of 2')
            ->assertExitCode(0);
    }

    public function test_before_the_migration_a_merged_source_with_a_registration_fails(): void
    {
        DB::table('attendees')->where('id', $this->attendees['ben'])->update(['merged_into_id' => $this->attendees['ana']]);

        $this->artisan('app:check-attendee-migration')
            ->expectsOutputToContain('the clean-up migration would stop')
            ->assertExitCode(1);
    }

    public function test_after_the_migration_every_check_passes(): void
    {
        $this->artisan('app:check-attendee-migration')->run();
        $this->runMigrations();

        $this->artisan('app:check-attendee-migration')
            ->expectsOutputToContain('Every qr_token resolves')
            ->expectsOutputToContain('Every photo path exists')
            ->expectsOutputToContain('All checks passed.')
            ->assertExitCode(0);
    }

    public function test_after_the_migration_a_missing_baseline_fails(): void
    {
        $this->runMigrations();

        $this->artisan('app:check-attendee-migration')->assertExitCode(1);
    }

    public function test_a_lost_attendance_record_fails(): void
    {
        $this->artisan('app:check-attendee-migration')->run();
        $this->runMigrations();
        DB::table('attendance_records')->delete();

        $this->artisan('app:check-attendee-migration')
            ->expectsOutputToContain('Attendance count unchanged')
            ->assertExitCode(1);
    }

    public function test_a_missing_attendee_row_fails(): void
    {
        $this->artisan('app:check-attendee-migration')->run();
        $this->runMigrations();
        DB::table('attendees')->where('legacy_registration_id', $this->registrations['ben_summit'])->delete();

        $this->artisan('app:check-attendee-migration')
            ->expectsOutputToContain('Attendee rows equal former registrations')
            ->assertExitCode(1);
    }

    public function test_a_qr_token_that_no_longer_resolves_fails(): void
    {
        $this->artisan('app:check-attendee-migration')->run();
        $this->runMigrations();
        DB::table('attendees')->where('legacy_registration_id', $this->registrations['ana_summit'])->update(['qr_token' => 'changed']);

        $this->artisan('app:check-attendee-migration')
            ->expectsOutputToContain('1 unresolved')
            ->assertExitCode(1);
    }

    public function test_a_photo_path_with_no_file_fails(): void
    {
        $this->artisan('app:check-attendee-migration')->run();
        $this->runMigrations();
        $path = json_decode(DB::table('attendees')->where('legacy_registration_id', $this->registrations['ben_summit'])->value('photo_paths'), true)['original'];
        Storage::disk('local')->delete($path);

        $this->artisan('app:check-attendee-migration')
            ->expectsOutputToContain('1 of 4 missing')
            ->assertExitCode(1);
    }

    public function test_a_photo_path_shared_with_another_attendee_fails(): void
    {
        $this->artisan('app:check-attendee-migration')->run();
        $this->runMigrations();
        $ana = DB::table('attendees')->where('legacy_registration_id', $this->registrations['ana_retreat'])->first();
        DB::table('attendees')->where('legacy_registration_id', $this->registrations['ana_summit'])->update(['photo_paths' => $ana->photo_paths]);

        $this->artisan('app:check-attendee-migration')
            ->expectsOutputToContain('1 of 4 missing')
            ->assertExitCode(1);
    }

    public function test_the_phase_must_match_the_schema(): void
    {
        $this->artisan('app:check-attendee-migration', ['--phase' => 'after'])->assertExitCode(1);
        $this->artisan('app:check-attendee-migration', ['--phase' => 'later'])->assertExitCode(1);

        $this->runMigrations();

        $this->artisan('app:check-attendee-migration', ['--phase' => 'before'])->assertExitCode(1);
    }

    private function runMigrations(): void
    {
        $this->split->up();
        $this->cleanup->up();
    }

    private function seedPreSplitData(): void
    {
        $now = now();
        $this->orgId = DB::table('organizations')->insertGetId(['name' => 'Central', 'code' => 'central', 'created_at' => $now, 'updated_at' => $now]);
        $staffId = DB::table('users')->insertGetId([
            'organization_id' => $this->orgId, 'name' => 'staff', 'email' => 'staff@example.test',
            'password' => 'not-a-real-hash', 'role' => 'admin', 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (['retreat', 'summit', 'camp'] as $event) {
            $this->events[$event] = DB::table('events')->insertGetId([
                'organization_id' => $this->orgId, 'name' => $event, 'start_date' => '2026-01-10', 'end_date' => '2026-01-11',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (['ana' => 'Cruz', 'ben' => 'Reyes', 'carl' => 'Santos', 'zed' => 'Tan'] as $first => $last) {
            $this->attendees[$first] = DB::table('attendees')->insertGetId([
                'organization_id' => $this->orgId, 'first_name' => ucfirst($first), 'last_name' => $last,
                'normalized_name' => "{$first} ".strtolower($last),
                'created_at' => '2025-11-01 00:00:00', 'updated_at' => '2025-11-01 00:00:00',
            ]);
        }

        DB::table('attendees')->where('id', $this->attendees['zed'])->update(['merged_into_id' => $this->attendees['ana']]);

        foreach (['ana', 'ben'] as $person) {
            $path = "attendees/{$this->attendees[$person]}/photo-original.webp";
            DB::table('attendees')->where('id', $this->attendees[$person])->update(['photo_paths' => json_encode(['original' => $path])]);
            Storage::disk('local')->put($path, $person);
        }

        $this->register('ana', 'retreat', '2026-01-01 09:00:00');
        $this->register('ana', 'summit', '2026-02-01 09:00:00');
        $this->register('ben', 'retreat', '2026-01-02 09:00:00');
        $this->register('ben', 'summit', '2026-02-02 09:00:00');

        $sessionId = DB::table('event_sessions')->insertGetId([
            'event_id' => $this->events['retreat'], 'name' => 'Day 1', 'session_date' => '2026-01-10',
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('attendance_records')->insert([
            'event_registration_id' => $this->registrations['ana_retreat'], 'event_session_id' => $sessionId,
            'check_in_at' => '2026-01-10 08:00:00', 'method' => 'qr', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Ana speaks at the summit and at the camp she is not registered for.
        foreach (['summit', 'camp'] as $event) {
            $programId = DB::table('event_programs')->insertGetId(['event_id' => $this->events[$event], 'created_at' => $now, 'updated_at' => $now]);
            DB::table('event_program_items')->insert([
                'event_program_id' => $programId, 'part_title' => "{$event} talk", 'attendee_id' => $this->attendees['ana'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Ana and Ben share two events, Ana and Carl none, Ben and Zed none.
        foreach ([['ana', 'ben'], ['ana', 'carl'], ['ben', 'zed']] as [$one, $two]) {
            DB::table('attendee_duplicate_dismissals')->insert([
                'organization_id' => $this->orgId, 'attendee_one_id' => $this->attendees[$one], 'attendee_two_id' => $this->attendees[$two],
                'name_fingerprint' => "{$one}-{$two}", 'dismissed_by' => $staffId, 'dismissed_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function register(string $attendee, string $event, string $registeredAt): void
    {
        $this->registrations["{$attendee}_{$event}"] = DB::table('event_registrations')->insertGetId([
            'event_id' => $this->events[$event], 'attendee_id' => $this->attendees[$attendee],
            'qr_token' => "token-{$attendee}-{$event}", 'registered_at' => $registeredAt,
            'created_at' => $registeredAt, 'updated_at' => $registeredAt,
        ]);
    }
}
