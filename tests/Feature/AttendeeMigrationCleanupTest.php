<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs the post-split clean-up migration against data seeded into the
 * pre-split schema and split by the real split migration.
 */
class AttendeeMigrationCleanupTest extends TestCase
{
    private const SPLIT = 'database/migrations/2026_10_22_000000_split_attendees_per_event.php';

    private const CLEANUP = 'database/migrations/2026_10_22_000001_clean_up_attendees_after_split.php';

    private const EXPORT = 'exports/unregistered-attendees-2026-10-22.csv';

    private Migration $split;

    private Migration $cleanup;

    private int $orgId;

    private int $staffId;

    /** @var array<string, int> */
    private array $events = [];

    /** @var array<string, int> */
    private array $attendees = [];

    /** @var array<string, int> */
    private array $registrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        // No RefreshDatabase transaction: SQLite ignores PRAGMA foreign_keys inside one,
        // so table rebuilds during ALTER would cascade-delete the seeded rows.
        $this->artisan('migrate:fresh');
        Storage::fake('local');
        Storage::fake('public');

        $this->split = require base_path(self::SPLIT);
        $this->cleanup = require base_path(self::CLEANUP);
        $this->split->down();
        $this->seedPreSplitData();
    }

    public function test_no_two_attendees_share_a_photo_file(): void
    {
        $this->runMigrations();

        $paths = DB::table('attendees')->whereNotNull('photo_paths')->pluck('photo_paths', 'id')
            ->flatMap(fn ($json, $id) => collect(json_decode($json, true))->map(fn ($path) => [$id, $path])->values())
            ->values();

        $this->assertGreaterThanOrEqual(3, $paths->count());
        $this->assertSame($paths->count(), $paths->pluck(1)->unique()->count());

        foreach ($paths as [$id, $path]) {
            $this->assertStringStartsWith("attendees/{$id}/photo-", $path);
            $this->assertTrue(Storage::disk('local')->exists($path), "{$path} is missing on the local disk");
        }
    }

    public function test_copied_photo_has_the_original_content(): void
    {
        $this->runMigrations();

        $copy = DB::table('attendees')->where('legacy_registration_id', $this->registrations['ana_summit'])->first();
        $path = json_decode($copy->photo_paths, true)['original'];

        $this->assertSame('ana-original', Storage::disk('local')->get($path));
    }

    public function test_legacy_public_photos_move_to_the_local_disk(): void
    {
        $this->runMigrations();

        Storage::disk('public')->assertMissing("attendees/{$this->attendees['ana']}/photo-original.webp");
        Storage::disk('local')->assertExists("attendees/{$this->attendees['ana']}/photo-original.webp");
        Storage::disk('local')->assertExists("attendees/{$this->attendees['ben']}/photo-original.webp");
    }

    public function test_a_row_whose_photo_file_is_missing_ends_up_with_no_photo(): void
    {
        DB::table('attendees')->where('id', $this->attendees['ben'])->update([
            'photo_paths' => json_encode(['original' => "attendees/{$this->attendees['ben']}/photo-original.webp", 'sm' => "attendees/{$this->attendees['ben']}/photo-sm.webp"]),
        ]);

        $this->runMigrations();

        $paths = json_decode(DB::table('attendees')->find($this->attendees['ben'])->photo_paths, true);
        $this->assertSame(['original'], array_keys($paths));
    }

    public function test_speaker_links_point_at_the_program_events_profile(): void
    {
        $this->runMigrations();

        $profileIn = fn (string $event) => (int) DB::table('attendees')
            ->where('event_id', $this->events[$event])
            ->where('first_name', 'Ana')
            ->value('id');

        $this->assertSame($profileIn('retreat'), (int) DB::table('event_program_items')->where('part_title', 'retreat talk')->value('attendee_id'));
        $this->assertSame($profileIn('summit'), (int) DB::table('event_program_items')->where('part_title', 'summit talk')->value('attendee_id'));
        $this->assertNotSame($profileIn('retreat'), $profileIn('summit'));
    }

    public function test_speaker_without_a_profile_in_the_program_event_keeps_only_the_name(): void
    {
        $this->runMigrations();

        $camp = DB::table('event_program_items')->where('part_title', 'camp talk')->first();
        $this->assertNull($camp->attendee_id);
        $this->assertSame('Ana C.', $camp->participant_name);

        $unnamed = DB::table('event_program_items')->where('part_title', 'unnamed talk')->first();
        $this->assertNull($unnamed->attendee_id);
        $this->assertSame('Carl Santos', $unnamed->participant_name);
    }

    public function test_dismissals_are_remapped_once_per_shared_event_and_other_pairs_are_dropped(): void
    {
        $this->runMigrations();

        $profile = fn (string $name, string $event) => (int) DB::table('attendees')
            ->where('event_id', $this->events[$event])->where('first_name', $name)->value('id');

        $pairs = DB::table('attendee_duplicate_dismissals')->get();
        $this->assertCount(2, $pairs);

        foreach (['retreat', 'summit'] as $event) {
            $pair = [$profile('Ana', $event), $profile('Ben', $event)];
            sort($pair);
            $row = $pairs->first(fn ($row) => (int) $row->attendee_one_id === $pair[0] && (int) $row->attendee_two_id === $pair[1]);

            $this->assertNotNull($row, "no dismissal for {$event}");
            $this->assertSame('fingerprint-ana-ben', $row->name_fingerprint);
            $this->assertSame($this->staffId, (int) $row->dismissed_by);
            $this->assertSame($this->orgId, (int) $row->organization_id);
        }
    }

    public function test_unregistered_attendees_are_exported_before_they_are_deleted(): void
    {
        $this->runMigrations();

        $csv = Storage::disk('local')->get(self::EXPORT);
        $this->assertStringContainsString('Carl', $csv);
        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertStringNotContainsString('Zed', $csv);
        $this->assertStringContainsString('+63917000000', $csv);
        $this->assertNull(DB::table('attendees')->find($this->attendees['carl']));
        $this->assertSame(0, DB::table('attendees')->whereNull('event_id')->count());
        Storage::disk('local')->assertMissing("attendees/{$this->attendees['carl']}/photo-original.webp");
    }

    public function test_merged_sources_are_deleted_and_their_target_stays(): void
    {
        $this->runMigrations();

        $this->assertNull(DB::table('attendees')->find($this->attendees['zed']));
        $this->assertSame(0, DB::table('attendees')->whereNotNull('merged_into_id')->count());
        $this->assertNotNull(DB::table('attendees')->find($this->attendees['ana']));
    }

    public function test_the_run_stops_when_a_merged_source_still_holds_a_registration(): void
    {
        $this->split->up();
        DB::table('attendees')->where('id', $this->attendees['ben'])->update(['merged_into_id' => $this->attendees['ana']]);

        try {
            $this->cleanup->up();
            $this->fail('The clean-up should have stopped.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('still hold a registration', $exception->getMessage());
        }

        $this->assertNotNull(DB::table('attendees')->find($this->attendees['carl']));
        $this->assertSame(1, DB::table('attendee_duplicate_dismissals')->where('attendee_one_id', $this->attendees['ana'])->where('attendee_two_id', $this->attendees['carl'])->count());
    }

    public function test_attendance_survives_the_clean_up(): void
    {
        $before = DB::table('attendance_records')->count();

        $this->runMigrations();

        $this->assertSame($before, DB::table('attendance_records')->count());
    }

    public function test_grid_downloads_and_their_files_are_deleted(): void
    {
        $this->runMigrations();

        $this->assertSame(0, DB::table('id_card_grid_downloads')->count());
        Storage::disk('local')->assertMissing('id-card-grids/retreat.zip');
    }

    public function test_attendee_users_lose_their_organization(): void
    {
        $this->runMigrations();

        $this->assertSame(0, DB::table('users')->where('role', 'attendee')->whereNotNull('organization_id')->count());
        $this->assertSame($this->orgId, (int) DB::table('users')->find($this->staffId)->organization_id);
    }

    public function test_audit_logs_are_untouched(): void
    {
        $before = DB::table('audit_logs')->orderBy('id')->get()->toArray();

        $this->runMigrations();

        $this->assertNotEmpty($before);
        $this->assertEquals($before, DB::table('audit_logs')->orderBy('id')->get()->toArray());
    }

    public function test_running_the_clean_up_again_changes_nothing(): void
    {
        $this->runMigrations();
        $attendees = DB::table('attendees')->orderBy('id')->get()->toArray();
        $dismissals = DB::table('attendee_duplicate_dismissals')->orderBy('id')->get()->toArray();
        $items = DB::table('event_program_items')->orderBy('id')->get()->toArray();

        $this->cleanup->up();

        $this->assertStringContainsString('Carl', Storage::disk('local')->get(self::EXPORT));
        $this->assertEquals($attendees, DB::table('attendees')->orderBy('id')->get()->toArray());
        $this->assertEquals($dismissals, DB::table('attendee_duplicate_dismissals')->orderBy('id')->get()->toArray());
        $this->assertEquals($items, DB::table('event_program_items')->orderBy('id')->get()->toArray());
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
        $this->staffId = $this->insertUser('staff@example.test', $this->orgId, 'admin');
        $this->insertUser('attendee@example.test', $this->orgId, 'attendee');

        foreach (['retreat', 'summit', 'camp'] as $event) {
            $this->events[$event] = DB::table('events')->insertGetId([
                'organization_id' => $this->orgId, 'name' => $event, 'start_date' => '2026-01-10', 'end_date' => '2026-01-11',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->attendees['ana'] = $this->insertAttendee('Ana', 'Cruz');
        $this->attendees['ben'] = $this->insertAttendee('Ben', 'Reyes');
        $this->attendees['carl'] = $this->insertAttendee('Carl', 'Santos', ['mobile_no' => '+63917000000']);
        Storage::disk('local')->put("attendees/{$this->attendees['carl']}/photo-original.webp", 'carl');
        $this->attendees['zed'] = $this->insertAttendee('Zed', 'Tan', ['merged_into_id' => $this->attendees['ana']]);
        $this->attendees['eve'] = $this->insertAttendee('=cmd', 'Eve');

        foreach (['ana' => 'ana-original', 'ben' => 'ben-original'] as $person => $content) {
            $path = "attendees/{$this->attendees[$person]}/photo-original.webp";
            DB::table('attendees')->where('id', $this->attendees[$person])->update(['photo_paths' => json_encode(['original' => $path])]);
            Storage::disk($person === 'ana' ? 'public' : 'local')->put($path, $content);
        }

        $this->registrations['ana_retreat'] = $this->insertRegistration('ana', 'retreat', '2026-01-01 09:00:00');
        $this->registrations['ana_summit'] = $this->insertRegistration('ana', 'summit', '2026-02-01 09:00:00');
        $this->registrations['ben_retreat'] = $this->insertRegistration('ben', 'retreat', '2026-01-02 09:00:00');
        $this->registrations['ben_summit'] = $this->insertRegistration('ben', 'summit', '2026-02-02 09:00:00');

        $sessionId = DB::table('event_sessions')->insertGetId([
            'event_id' => $this->events['retreat'], 'name' => 'Day 1', 'session_date' => '2026-01-10',
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('attendance_records')->insert([
            'event_registration_id' => $this->registrations['ana_retreat'], 'event_session_id' => $sessionId,
            'check_in_at' => '2026-01-10 08:00:00', 'method' => 'qr', 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (['retreat', 'summit', 'camp'] as $event) {
            $programs[$event] = DB::table('event_programs')->insertGetId(['event_id' => $this->events[$event], 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->insertItem($programs['retreat'], 'retreat talk', $this->attendees['ana']);
        $this->insertItem($programs['summit'], 'summit talk', $this->attendees['ana']);
        $this->insertItem($programs['camp'], 'camp talk', $this->attendees['ana'], 'Ana C.');
        $this->insertItem($programs['camp'], 'unnamed talk', $this->attendees['carl']);

        $this->insertDismissal('ana', 'ben', 'fingerprint-ana-ben');
        $this->insertDismissal('ana', 'carl', 'fingerprint-ana-carl');

        Storage::disk('local')->put('id-card-grids/retreat.zip', 'zip');
        DB::table('id_card_grid_downloads')->insert([
            'event_id' => $this->events['retreat'], 'requested_by' => $this->staffId, 'status' => 'completed',
            'file_path' => 'id-card-grids/retreat.zip', 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'organization_id' => $this->orgId, 'user_id' => $this->staffId, 'action' => 'attendee.created',
            'auditable_type' => 'attendee', 'auditable_id' => $this->attendees['carl'], 'created_at' => $now,
        ]);
    }

    private function insertUser(string $email, int $organizationId, string $role): int
    {
        return DB::table('users')->insertGetId([
            'organization_id' => $organizationId, 'name' => $email, 'email' => $email,
            'password' => 'not-a-real-hash', 'role' => $role, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertAttendee(string $first, string $last, array $attributes = []): int
    {
        return DB::table('attendees')->insertGetId([
            'organization_id' => $this->orgId, 'first_name' => $first, 'last_name' => $last,
            'normalized_name' => mb_strtolower("{$first} {$last}"),
            'created_at' => '2025-11-01 00:00:00', 'updated_at' => '2025-11-01 00:00:00',
            ...$attributes,
        ]);
    }

    private function insertRegistration(string $attendee, string $event, string $registeredAt): int
    {
        return DB::table('event_registrations')->insertGetId([
            'event_id' => $this->events[$event], 'attendee_id' => $this->attendees[$attendee],
            'qr_token' => "token-{$attendee}-{$event}", 'registered_at' => $registeredAt,
            'created_at' => $registeredAt, 'updated_at' => $registeredAt,
        ]);
    }

    private function insertItem(int $programId, string $title, int $attendeeId, ?string $participantName = null): void
    {
        DB::table('event_program_items')->insert([
            'event_program_id' => $programId, 'part_title' => $title, 'attendee_id' => $attendeeId,
            'participant_name' => $participantName, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertDismissal(string $one, string $two, string $fingerprint): void
    {
        DB::table('attendee_duplicate_dismissals')->insert([
            'organization_id' => $this->orgId, 'attendee_one_id' => $this->attendees[$one], 'attendee_two_id' => $this->attendees[$two],
            'name_fingerprint' => $fingerprint, 'dismissed_by' => $this->staffId, 'dismissed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
