<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs the per-event Attendee migration against data seeded into the
 * pre-migration schema. Seeding goes through the query builder so the test
 * does not depend on models that later slices change.
 */
class AttendeePerEventMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_22_000000_split_attendees_per_event.php';

    private Migration $migration;

    private int $orgId;

    private int $staffId;

    /** @var array<string, int> */
    private array $events = [];

    /** @var array<string, int> */
    private array $sessions = [];

    /** @var array<string, int> */
    private array $attendees = [];

    /** @var array<string, int> */
    private array $registrations = [];

    /** @var array<string, int> */
    private array $attendance = [];

    protected function setUp(): void
    {
        parent::setUp();

        // No RefreshDatabase transaction: SQLite ignores PRAGMA foreign_keys inside one,
        // so table rebuilds during ALTER would cascade-delete the seeded rows. The
        // in-memory database is new for every test.
        $this->artisan('migrate:fresh');

        $this->migration = require base_path(self::MIGRATION);
        $this->migration->down();
    }

    public function test_down_restores_the_pre_migration_schema(): void
    {
        $this->assertTrue(Schema::hasTable('event_registrations'));
        $this->assertFalse(Schema::hasColumn('attendees', 'event_id'));
        $this->assertFalse(Schema::hasColumn('attendees', 'legacy_registration_id'));
        $this->assertTrue(Schema::hasColumn('attendance_records', 'event_registration_id'));
        $this->assertFalse(Schema::hasColumn('attendance_records', 'attendee_id'));
    }

    public function test_up_adds_the_registration_columns_to_attendees(): void
    {
        $this->migration->up();

        foreach (['event_id', 'qr_token', 'id_card_path', 'id_card_images_path', 'id_card_generated_at', 'registered_by', 'registered_at', 'legacy_registration_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('attendees', $column), "attendees.{$column} is missing");
        }

        $this->assertTrue(Schema::hasColumn('attendance_records', 'attendee_id'));
        $this->assertFalse(Schema::hasColumn('attendance_records', 'event_registration_id'));
    }

    public function test_new_row_count_equals_former_registration_count(): void
    {
        $this->seedPreMigrationData();
        $registrationCount = DB::table('event_registrations')->count();

        $this->migration->up();

        $this->assertSame($registrationCount, DB::table('attendees')->whereNotNull('event_id')->count());
        $this->assertSame($registrationCount, DB::table('attendees')->whereNotNull('legacy_registration_id')->count());
    }

    public function test_earliest_registration_keeps_the_original_row(): void
    {
        $this->seedPreMigrationData();

        $this->migration->up();

        $original = DB::table('attendees')->find($this->attendees['ana']);
        $this->assertSame($this->events['retreat'], (int) $original->event_id);
        $this->assertSame($this->registrations['ana_retreat'], (int) $original->legacy_registration_id);
        $this->assertSame('token-ana-retreat', $original->qr_token);
        $this->assertSame('id-cards/ana-retreat.pdf', $original->id_card_path);
        $this->assertSame(['id-cards/ana-retreat-1.png'], json_decode($original->id_card_images_path, true));
        $this->assertSame('2026-01-05 08:00:00', Carbon::parse($original->id_card_generated_at)->toDateTimeString());
        $this->assertSame($this->staffId, (int) $original->registered_by);
        $this->assertSame('2026-01-01 09:00:00', Carbon::parse($original->registered_at)->toDateTimeString());
        $this->assertSame('invite-ana', $original->invite_token);
    }

    public function test_other_registrations_get_copied_rows(): void
    {
        $this->seedPreMigrationData();
        $original = DB::table('attendees')->find($this->attendees['ana']);

        $this->migration->up();

        foreach (['ana_summit' => ['summit', 'token-ana-summit'], 'ana_camp' => ['camp', 'token-ana-camp']] as $registration => [$event, $token]) {
            $copy = DB::table('attendees')->where('legacy_registration_id', $this->registrations[$registration])->first();

            $this->assertNotNull($copy);
            $this->assertNotSame($this->attendees['ana'], (int) $copy->id);
            $this->assertSame($this->events[$event], (int) $copy->event_id);
            $this->assertSame($token, $copy->qr_token);

            foreach (['first_name', 'middle_name', 'last_name', 'normalized_name', 'email_address', 'mobile_no', 'photo_paths', 'union_id', 'mission_id', 'church_id', 'organization_level', 'remarks', 'user_id', 'created_by'] as $field) {
                $this->assertEquals($original->{$field}, $copy->{$field}, "{$field} was not copied");
            }

            $this->assertSame($this->orgId, (int) $copy->organization_id);
            $this->assertNull($copy->invite_token);
            $this->assertNull($copy->invited_at);
        }
    }

    public function test_attendee_without_registrations_is_left_without_an_event(): void
    {
        $this->seedPreMigrationData();

        $this->migration->up();

        $unregistered = DB::table('attendees')->find($this->attendees['carl']);
        $this->assertNull($unregistered->event_id);
        $this->assertNull($unregistered->legacy_registration_id);
        $this->assertNull($unregistered->qr_token);
    }

    public function test_attendance_count_is_unchanged_and_every_record_points_at_the_right_per_event_row(): void
    {
        $this->seedPreMigrationData();
        $before = DB::table('attendance_records')
            ->join('event_sessions', 'event_sessions.id', '=', 'attendance_records.event_session_id')
            ->get(['attendance_records.id', 'attendance_records.event_registration_id', 'event_sessions.event_id'])
            ->keyBy('id');

        $this->migration->up();

        $this->assertSame($before->count(), DB::table('attendance_records')->count());

        foreach (DB::table('attendance_records')->get() as $record) {
            $attendee = DB::table('attendees')->find($record->attendee_id);

            $this->assertSame((int) $before[$record->id]->event_registration_id, (int) $attendee->legacy_registration_id);
            $this->assertSame((int) $before[$record->id]->event_id, (int) $attendee->event_id);
        }
    }

    public function test_every_qr_token_still_resolves_to_the_same_person_and_event(): void
    {
        $this->seedPreMigrationData();
        $before = DB::table('event_registrations')
            ->join('attendees', 'attendees.id', '=', 'event_registrations.attendee_id')
            ->get(['event_registrations.qr_token', 'event_registrations.event_id', 'attendees.first_name', 'attendees.last_name', 'attendees.user_id', 'attendees.email_address']);

        $this->migration->up();

        foreach ($before as $registration) {
            $attendee = DB::table('attendees')->where('qr_token', $registration->qr_token)->sole();

            $this->assertSame((int) $registration->event_id, (int) $attendee->event_id);
            $this->assertSame($registration->first_name, $attendee->first_name);
            $this->assertSame($registration->last_name, $attendee->last_name);
            $this->assertEquals($registration->user_id, $attendee->user_id);
            $this->assertEquals($registration->email_address, $attendee->email_address);
        }
    }

    public function test_user_and_email_are_unique_per_event_only(): void
    {
        $this->seedPreMigrationData();
        $this->migration->up();

        $ana = DB::table('attendees')->find($this->attendees['ana']);
        $newEvent = $this->insertEvent('Conference');

        // Same person in a further event is allowed.
        $this->insertAttendee('Ana', 'Cruz', ['event_id' => $newEvent, 'user_id' => $ana->user_id, 'email_address' => $ana->email_address]);

        // Several Attendees without email in one event are allowed.
        $this->insertAttendee('No', 'Email', ['event_id' => $newEvent]);
        $this->insertAttendee('Also', 'Noemail', ['event_id' => $newEvent]);

        $this->assertThrows(
            fn () => $this->insertAttendee('Ana', 'Again', ['event_id' => $newEvent, 'user_id' => $ana->user_id]),
            QueryException::class,
        );
        $this->assertThrows(
            fn () => $this->insertAttendee('Ana', 'Again', ['event_id' => $newEvent, 'email_address' => $ana->email_address]),
            QueryException::class,
        );
    }

    public function test_attendance_is_unique_per_attendee_and_session(): void
    {
        $this->seedPreMigrationData();
        $this->migration->up();

        $existing = DB::table('attendance_records')->find($this->attendance['ana_summit_day1']);

        $this->assertThrows(
            fn () => DB::table('attendance_records')->insert([
                'attendee_id' => $existing->attendee_id,
                'event_session_id' => $existing->event_session_id,
                'method' => 'qr',
            ]),
            QueryException::class,
        );
    }

    public function test_up_is_idempotent(): void
    {
        $this->seedPreMigrationData();
        $this->migration->up();
        $attendees = DB::table('attendees')->orderBy('id')->get();
        $attendance = DB::table('attendance_records')->orderBy('id')->get();

        $this->migration->up();

        $this->assertEquals($attendees, DB::table('attendees')->orderBy('id')->get());
        $this->assertEquals($attendance, DB::table('attendance_records')->orderBy('id')->get());
    }

    public function test_up_resumes_after_a_partial_split(): void
    {
        $this->seedPreMigrationData();
        $this->migration->up();
        $expected = DB::table('attendees')->whereNotNull('legacy_registration_id')->orderBy('legacy_registration_id')->pluck('qr_token', 'legacy_registration_id');

        // Simulate a run that stopped after the first copy was written.
        $lastCopy = DB::table('attendees')->where('legacy_registration_id', $this->registrations['ana_camp'])->value('id');
        DB::table('attendance_records')->where('attendee_id', $lastCopy)->delete();
        DB::table('attendees')->delete($lastCopy);

        $this->migration->up();

        $this->assertEquals($expected, DB::table('attendees')->whereNotNull('legacy_registration_id')->orderBy('legacy_registration_id')->pluck('qr_token', 'legacy_registration_id'));
    }

    public function test_down_restores_registrations_and_attendance(): void
    {
        $this->seedPreMigrationData();
        $attendees = DB::table('attendees')->orderBy('id')->get();
        $registrations = DB::table('event_registrations')->orderBy('id')->get();
        $attendance = DB::table('attendance_records')->orderBy('id')->get(['id', 'event_registration_id', 'event_session_id']);

        $this->migration->up();
        $this->migration->down();

        $this->assertEquals($attendees, DB::table('attendees')->orderBy('id')->get());
        $this->assertEquals($registrations, DB::table('event_registrations')->orderBy('id')->get());
        $this->assertEquals($attendance, DB::table('attendance_records')->orderBy('id')->get(['id', 'event_registration_id', 'event_session_id']));
    }

    public function test_down_writes_later_id_card_changes_back_to_registrations(): void
    {
        $this->seedPreMigrationData();
        $this->migration->up();

        DB::table('attendees')
            ->where('legacy_registration_id', $this->registrations['ana_summit'])
            ->update(['id_card_path' => 'id-cards/regenerated.pdf']);

        $this->migration->down();

        $this->assertSame('id-cards/regenerated.pdf', DB::table('event_registrations')->where('id', $this->registrations['ana_summit'])->value('id_card_path'));
    }

    public function test_down_refuses_when_attendees_were_created_after_the_split(): void
    {
        $this->seedPreMigrationData();
        $this->migration->up();
        $this->insertAttendee('New', 'Person', ['event_id' => $this->events['camp']]);

        $this->assertThrows(fn () => $this->migration->down(), RuntimeException::class);
        $this->assertTrue(Schema::hasColumn('attendees', 'event_id'));
    }

    private function seedPreMigrationData(): void
    {
        $this->orgId = DB::table('organizations')->insertGetId([
            'name' => 'Central Conference',
            'code' => 'central',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->staffId = $this->insertUser('staff@example.test', $this->orgId);
        $anaUser = $this->insertUser('ana@example.test', null);

        $this->events['retreat'] = $this->insertEvent('Retreat');
        $this->events['summit'] = $this->insertEvent('Summit');
        $this->events['camp'] = $this->insertEvent('Camp');

        foreach ($this->events as $key => $eventId) {
            $this->sessions["{$key}_day1"] = $this->insertSession($eventId, 'Day 1');
            $this->sessions["{$key}_day2"] = $this->insertSession($eventId, 'Day 2');
        }

        $this->attendees['ana'] = $this->insertAttendee('Ana', 'Cruz', [
            'middle_name' => 'Maria',
            'user_id' => $anaUser,
            'email_address' => 'ana@example.test',
            'mobile_no' => '09170000001',
            'photo_paths' => json_encode(['original' => 'attendees/1/photo-original.webp']),
            'organization_level' => 'local',
            'remarks' => 'Vegetarian',
            'invite_token' => 'invite-ana',
            'invited_at' => '2025-12-01 00:00:00',
            'created_by' => $this->staffId,
        ]);
        $this->attendees['ben'] = $this->insertAttendee('Ben', 'Reyes', ['email_address' => 'ben@example.test']);
        $this->attendees['carl'] = $this->insertAttendee('Carl', 'Santos');

        // Inserted out of date order so the earliest is not the lowest id.
        $this->registrations['ana_summit'] = $this->insertRegistration('ana', 'summit', 'token-ana-summit', '2026-02-01 09:00:00');
        $this->registrations['ana_retreat'] = $this->insertRegistration('ana', 'retreat', 'token-ana-retreat', '2026-01-01 09:00:00', [
            'id_card_path' => 'id-cards/ana-retreat.pdf',
            'id_card_images_path' => json_encode(['id-cards/ana-retreat-1.png']),
            'id_card_generated_at' => '2026-01-05 08:00:00',
            'registered_by' => $this->staffId,
        ]);
        $this->registrations['ana_camp'] = $this->insertRegistration('ana', 'camp', 'token-ana-camp', '2026-03-01 09:00:00');
        $this->registrations['ben_summit'] = $this->insertRegistration('ben', 'summit', 'token-ben-summit', '2026-02-02 09:00:00');

        $this->attendance['ana_retreat_day1'] = $this->insertAttendance('ana_retreat', 'retreat_day1');
        $this->attendance['ana_summit_day1'] = $this->insertAttendance('ana_summit', 'summit_day1');
        $this->attendance['ana_summit_day2'] = $this->insertAttendance('ana_summit', 'summit_day2');
        $this->attendance['ana_camp_day1'] = $this->insertAttendance('ana_camp', 'camp_day1');
        $this->attendance['ben_summit_day1'] = $this->insertAttendance('ben_summit', 'summit_day1');
    }

    private function insertUser(string $email, ?int $organizationId): int
    {
        return DB::table('users')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $email,
            'email' => $email,
            'password' => 'not-a-real-hash',
            'role' => $organizationId === null ? 'attendee' : 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEvent(string $name): int
    {
        return DB::table('events')->insertGetId([
            'organization_id' => $this->orgId,
            'name' => $name,
            'start_date' => '2026-01-10',
            'end_date' => '2026-01-11',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSession(int $eventId, string $name): int
    {
        return DB::table('event_sessions')->insertGetId([
            'event_id' => $eventId,
            'name' => $name,
            'session_date' => '2026-01-10',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertAttendee(string $first, string $last, array $attributes = []): int
    {
        return DB::table('attendees')->insertGetId([
            'organization_id' => $this->orgId,
            'first_name' => $first,
            'last_name' => $last,
            'normalized_name' => mb_strtolower("{$first} {$last}"),
            'created_at' => '2025-11-01 00:00:00',
            'updated_at' => '2025-11-01 00:00:00',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertRegistration(string $attendee, string $event, string $token, string $registeredAt, array $attributes = []): int
    {
        return DB::table('event_registrations')->insertGetId([
            'event_id' => $this->events[$event],
            'attendee_id' => $this->attendees[$attendee],
            'qr_token' => $token,
            'registered_at' => $registeredAt,
            'created_at' => $registeredAt,
            'updated_at' => $registeredAt,
            ...$attributes,
        ]);
    }

    private function insertAttendance(string $registration, string $session): int
    {
        return DB::table('attendance_records')->insertGetId([
            'event_registration_id' => $this->registrations[$registration],
            'event_session_id' => $this->sessions[$session],
            'check_in_at' => '2026-01-10 08:00:00',
            'method' => 'qr',
            'created_at' => '2026-01-10 08:00:00',
            'updated_at' => '2026-01-10 08:00:00',
        ]);
    }
}
