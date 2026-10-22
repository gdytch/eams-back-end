<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private ?string $crashPattern = null;

    private int $crashCountdown = 0;

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

    public function test_original_row_takes_the_organization_of_its_earliest_event(): void
    {
        $this->seedPreMigrationData();
        $otherOrgId = DB::table('organizations')->insertGetId([
            'name' => 'North Conference',
            'code' => 'north',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->events['north_rally'] = $this->insertEvent('North Rally', $otherOrgId);
        $this->attendees['dan'] = $this->insertAttendee('Dan', 'Lim', ['email_address' => 'dan@example.test']);
        $this->registrations['dan_rally'] = $this->insertRegistration('dan', 'north_rally', 'token-dan-rally', '2026-01-03 09:00:00');
        $this->registrations['dan_camp'] = $this->insertRegistration('dan', 'camp', 'token-dan-camp', '2026-03-03 09:00:00');

        $this->migration->up();

        $original = DB::table('attendees')->find($this->attendees['dan']);
        $this->assertSame($this->events['north_rally'], (int) $original->event_id);
        $this->assertSame($otherOrgId, (int) $original->organization_id);

        $copy = DB::table('attendees')->where('legacy_registration_id', $this->registrations['dan_camp'])->first();
        $this->assertSame($this->orgId, (int) $copy->organization_id);
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

    /**
     * MySQL commits each DDL statement on its own. Each crash point names
     * the first statement of a step whose re-run used to skip or fail, as
     * [SQLite statement, occurrence] and [MySQL statement, occurrence].
     * SQLite runs a column change or foreign key change as a table rebuild
     * that starts with create table "__temp__…".
     *
     * @return array<string, array{array{string, int}, array{string, int}}>
     */
    public static function upCrashPoints(): array
    {
        return [
            'qr_token column' => [['/add column "qr_token"/', 1], ['/add `qr_token`/', 1]],
            'qr_token unique' => [['/create unique index "attendees_qr_token_unique"/', 1], ['/add unique `attendees_qr_token_unique`/', 1]],
            'global user unique dropped' => [['/drop index "attendees_user_id_unique"/', 1], ['/drop index `attendees_user_id_unique`/', 1]],
            'global email unique dropped' => [['/drop index "attendees_email_address_unique"/', 1], ['/drop index `attendees_email_address_unique`/', 1]],
            'per-event user unique' => [['/create unique index "attendees_event_id_user_id_unique"/', 1], ['/add unique `attendees_event_id_user_id_unique`/', 1]],
            'old attendance unique dropped' => [['/drop index "attendance_records_event_registration_id_event_session_id_unique"/', 1], ['/drop index `attendance_records_event_registration_id_event_session_id_unique`/', 1]],
            'event_registration_id dropped' => [['/drop column "event_registration_id"/', 1], ['/drop `event_registration_id`/', 1]],
            'attendee_id made required' => [['/create table "__temp__attendance_records"/', 2], ['/modify `attendee_id`/', 1]],
            'attendee_id foreign key' => [['/create table "__temp__attendance_records"/', 3], ['/add constraint `attendance_records_attendee_id_foreign`/', 1]],
        ];
    }

    #[DataProvider('upCrashPoints')]
    public function test_up_resumes_after_a_crash_between_schema_statements(array $sqlite, array $mysql): void
    {
        $this->seedPreMigrationData();
        $registrationCount = DB::table('event_registrations')->count();
        $attendanceCount = DB::table('attendance_records')->count();

        $this->crashBefore(DB::getDriverName() === 'mysql' ? $mysql : $sqlite);
        $this->assertThrows(fn () => $this->migration->up(), RuntimeException::class, 'Simulated crash');
        $this->crashPattern = null;

        $this->migration->up();

        $attendeeId = $this->column('attendance_records', 'attendee_id');
        $this->assertNotNull($attendeeId);
        $this->assertFalse($attendeeId['nullable']);
        $this->assertFalse(Schema::hasColumn('attendance_records', 'event_registration_id'));
        $this->assertTrue(Schema::hasIndex('attendance_records', 'attendance_records_attendee_id_event_session_id_unique', 'unique'));
        $this->assertSame('attendees', $this->foreignKey('attendance_records', 'attendee_id')['foreign_table'] ?? null);

        foreach (['attendees_qr_token_unique', 'attendees_legacy_registration_id_unique', 'attendees_event_id_user_id_unique', 'attendees_event_id_email_address_unique'] as $index) {
            $this->assertTrue(Schema::hasIndex('attendees', $index, 'unique'), "{$index} is missing");
        }

        $this->assertFalse(Schema::hasIndex('attendees', 'attendees_user_id_unique'));
        $this->assertFalse(Schema::hasIndex('attendees', 'attendees_email_address_unique'));
        $this->assertNotNull($this->foreignKey('attendees', 'event_id'));
        $this->assertNotNull($this->foreignKey('attendees', 'registered_by'));

        $this->assertSame($registrationCount, DB::table('attendees')->whereNotNull('legacy_registration_id')->count());
        $this->assertSame($attendanceCount, DB::table('attendance_records')->count());
        $this->assertSame(0, DB::table('attendance_records')
            ->join('attendees', 'attendees.id', '=', 'attendance_records.attendee_id')
            ->join('event_sessions', 'event_sessions.id', '=', 'attendance_records.event_session_id')
            ->whereColumn('attendees.event_id', '!=', 'event_sessions.event_id')
            ->count());
    }

    /**
     * @return array<string, array{array{string, int}, array{string, int}}>
     */
    public static function downCrashPoints(): array
    {
        return [
            'attendee_id foreign key dropped' => [['/create table "__temp__attendance_records"/', 1], ['/drop foreign key `attendance_records_attendee_id_foreign`/', 1]],
            'new attendance unique dropped' => [['/drop index "attendance_records_attendee_id_event_session_id_unique"/', 1], ['/drop index `attendance_records_attendee_id_event_session_id_unique`/', 1]],
            'event_registration_id made required' => [['/create table "__temp__attendance_records"/', 2], ['/modify `event_registration_id`/', 1]],
            'copied attendees deleted' => [['/^delete from "attendees"/', 1], ['/^delete from `attendees`/', 1]],
            'per-event email unique dropped' => [['/drop index "attendees_event_id_email_address_unique"/', 1], ['/drop index `attendees_event_id_email_address_unique`/', 1]],
            'user_id index dropped' => [['/drop index "attendees_user_id_index"/', 1], ['/drop index `attendees_user_id_index`/', 1]],
            'legacy unique dropped' => [['/drop index "attendees_legacy_registration_id_unique"/', 1], ['/drop index `attendees_legacy_registration_id_unique`/', 1]],
            'registration columns dropped' => [['/drop column "event_id"/', 1], ['/drop `legacy_registration_id`/', 1]],
        ];
    }

    #[DataProvider('downCrashPoints')]
    public function test_down_resumes_after_a_crash_between_schema_statements(array $sqlite, array $mysql): void
    {
        $this->seedPreMigrationData();
        $attendees = DB::table('attendees')->orderBy('id')->get();
        $registrations = DB::table('event_registrations')->orderBy('id')->get();
        $attendance = DB::table('attendance_records')->orderBy('id')->get(['id', 'event_registration_id', 'event_session_id']);
        $this->migration->up();

        $this->crashBefore(DB::getDriverName() === 'mysql' ? $mysql : $sqlite);
        $this->assertThrows(fn () => $this->migration->down(), RuntimeException::class, 'Simulated crash');
        $this->crashPattern = null;

        $this->migration->down();

        $registrationId = $this->column('attendance_records', 'event_registration_id');
        $this->assertNotNull($registrationId);
        $this->assertFalse($registrationId['nullable']);
        $this->assertFalse(Schema::hasColumn('attendance_records', 'attendee_id'));
        $this->assertTrue(Schema::hasIndex('attendance_records', 'attendance_records_event_registration_id_event_session_id_unique', 'unique'));
        $this->assertSame('event_registrations', $this->foreignKey('attendance_records', 'event_registration_id')['foreign_table'] ?? null);

        $this->assertTrue(Schema::hasIndex('attendees', 'attendees_user_id_unique', 'unique'));
        $this->assertTrue(Schema::hasIndex('attendees', 'attendees_email_address_unique', 'unique'));
        $this->assertFalse(Schema::hasIndex('attendees', 'attendees_user_id_index'));

        $this->assertEquals($attendees, DB::table('attendees')->orderBy('id')->get());
        $this->assertEquals($registrations, DB::table('event_registrations')->orderBy('id')->get());
        $this->assertEquals($attendance, DB::table('attendance_records')->orderBy('id')->get(['id', 'event_registration_id', 'event_session_id']));
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

    /**
     * Makes the next run throw before the given occurrence of a matching
     * SQL statement, as a lost connection would.
     *
     * @param  array{string, int}  $crashPoint
     */
    private function crashBefore(array $crashPoint): void
    {
        [$this->crashPattern, $this->crashCountdown] = $crashPoint;

        DB::connection()->beforeExecuting(function (string $sql) {
            if ($this->crashPattern !== null && preg_match($this->crashPattern, $sql) && --$this->crashCountdown === 0) {
                $this->crashPattern = null;

                throw new RuntimeException("Simulated crash before: {$sql}");
            }
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function column(string $table, string $column): ?array
    {
        return collect(Schema::getColumns($table))->firstWhere('name', $column);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function foreignKey(string $table, string $column): ?array
    {
        return collect(Schema::getForeignKeys($table))->first(fn (array $foreignKey) => $foreignKey['columns'] === [$column]);
    }

    private function insertEvent(string $name, ?int $organizationId = null): int
    {
        return DB::table('events')->insertGetId([
            'organization_id' => $organizationId ?? $this->orgId,
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
