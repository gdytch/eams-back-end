<?php

namespace Tests\Feature;

use App\Enums\IdCardGridDownloadStatus;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventProgramItem;
use App\Models\EventSession;
use App\Models\IdCardGridDownload;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArchivedEventWriteBlockTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ARCHIVED_MESSAGE = 'This event is archived.';

    private Organization $organization;

    private Event $event;

    private EventSession $session;

    private Speaker $speaker;

    private EventProgramItem $item;

    private Attendee $attendee;

    private Attendee $duplicate;

    private AttendanceRecord $record;

    private User $checkerTarget;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * Every blocked write, as [method, uri, body, roles]. Placeholders such as
     * `:event` are replaced with fixture ids, in the uri and in body values.
     *
     * @return array<string, array{string, string, array<string, mixed>, list<string>}>
     */
    private static function blockedEndpoints(): array
    {
        $admins = ['super_admin', 'org_admin'];
        $staff = ['super_admin', 'org_admin', 'checker'];
        $everyone = ['super_admin', 'org_admin', 'checker', 'attendee'];

        return [
            'event update' => ['PUT', 'events/:event', ['name' => 'Renamed'], $admins],
            'banner upload' => ['POST', 'events/:event/banner', [], $admins],
            'banner remove' => ['DELETE', 'events/:event/banner', [], $admins],
            'id card background upload' => ['POST', 'events/:event/id-card-background', [], $admins],
            'id card background remove' => ['DELETE', 'events/:event/id-card-background', [], $admins],
            'speaker settings' => ['PUT', 'events/:event/speaker-settings', ['speakers_public' => true], $admins],
            'speaker store' => ['POST', 'events/:event/speakers', ['name' => 'New'], $admins],
            'speaker update' => ['PUT', 'events/:event/speakers/:speaker', ['name' => 'New'], $admins],
            'speaker destroy' => ['DELETE', 'events/:event/speakers/:speaker', [], $admins],
            'speaker photo' => ['POST', 'events/:event/speakers/:speaker/photo', [], $admins],
            'session store' => ['POST', 'events/:event/sessions', ['name' => 'New'], $admins],
            'session update' => ['PUT', 'events/:event/sessions/:session', ['name' => 'New'], $admins],
            'session destroy' => ['DELETE', 'events/:event/sessions/:session', [], $admins],
            'program metadata' => ['PUT', 'events/:event/program', ['title' => 'New'], $admins],
            'program builder store' => ['POST', 'events/:event/program/builder/days', ['date' => '2030-01-01'], $admins],
            'program builder reorder' => ['PUT', 'events/:event/program/builder/days/order', ['ids' => [1]], $admins],
            'program builder update' => ['PUT', 'events/:event/program/builder/days/1', ['date' => '2030-01-01'], $admins],
            'program builder destroy' => ['DELETE', 'events/:event/program/builder/days/1', [], $admins],
            'program builder duplicate' => ['POST', 'events/:event/program/builder/days/1/duplicate', [], $admins],
            'program store' => ['POST', 'events/:event/program', [], $admins],
            'program destroy' => ['DELETE', 'events/:event/program', [], $admins],
            'program item store' => ['POST', 'events/:event/program/items', ['part_title' => 'New'], $admins],
            'program item reorder' => ['POST', 'events/:event/program/items/reorder', ['items' => [['id' => ':item', 'order' => 1]]], $admins],
            'program item update' => ['PUT', 'events/:event/program/items/:item', ['part_title' => 'New'], $admins],
            'program item destroy' => ['DELETE', 'events/:event/program/items/:item', [], $admins],
            'program item photo upload' => ['POST', 'events/:event/program/items/:item/photos', [], $admins],
            'program item photo remove' => ['DELETE', 'events/:event/program/items/:item/photos', ['index' => 0], $admins],
            'registration store' => ['POST', 'events/:event/registrations', ['attendee_id' => ':attendee'], $staff],
            'registration destroy' => ['DELETE', 'events/:event/registrations/:attendee', [], $admins],
            'id card regenerate' => ['POST', 'events/:event/registrations/:attendee/id-card/regenerate', [], $admins],
            'attendance scan' => ['POST', 'attendance/scan', ['event_id' => ':event', 'session_id' => ':session', 'qr_token' => ':qr'], $staff],
            'attendance manual' => ['POST', 'attendance/manual', ['event_id' => ':event', 'session_id' => ':session', 'attendee_id' => ':attendee'], $staff],
            'attendance check-out' => ['POST', 'attendance/:record/check-out', [], $staff],
            'public wizard basic-info' => ['PUT', 'public/event/:invite/registration/basic-info', [], $everyone],
            'public wizard territory' => ['PUT', 'public/event/:invite/registration/territory', [], $everyone],
            'public wizard finish' => ['POST', 'public/event/:invite/registration/finish', [], $everyone],
            'attendee update' => ['PUT', 'attendees/:attendee', ['first_name' => 'Changed'], $admins],
            'attendee destroy' => ['DELETE', 'attendees/:attendee', [], $admins],
            'attendee photo upload' => ['POST', 'attendees/:attendee/photo', [], $admins],
            'attendee photo remove' => ['DELETE', 'attendees/:attendee/photo', [], $admins],
            'attendee duplicate merge' => ['POST', 'attendees/duplicates/merge', ['primary_attendee_id' => ':attendee', 'duplicate_attendee_ids' => [':duplicate']], $admins],
            'attendee store with event' => ['POST', 'attendees', ['first_name' => 'New', 'last_name' => 'Person', 'event_id' => ':event'], $admins],
            'checker event access grant' => ['PUT', 'users/:checker/event-access', ['event_ids' => [':event']], $admins],
        ];
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>, string}>
     */
    public static function blockedEndpointRoles(): iterable
    {
        foreach (self::blockedEndpoints() as $name => [$method, $uri, $body, $roles]) {
            foreach ($roles as $role) {
                yield "{$name} as {$role}" => [$method, $uri, $body, $role];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('blockedEndpointRoles')]
    public function test_blocked_endpoint_returns_archived_403(string $method, string $uri, array $body, string $role): void
    {
        $this->buildArchivedFixture();
        $actor = $this->actor($role);
        $before = $this->snapshot();

        $response = $this->actingAs($actor, 'sanctum')
            ->json($method, '/api/v1/'.$this->resolve($uri), $this->resolve($body));

        $response->assertForbidden()->assertExactJson(['message' => self::ARCHIVED_MESSAGE]);
        $this->assertSame($before, $this->snapshot());
        Queue::assertNothingPushed();
    }

    public function test_unauthorized_user_still_gets_generic_403_on_archived_event(): void
    {
        $this->buildArchivedFixture();
        $checker = $this->actor('checker');

        $this->actingAs($checker, 'sanctum')
            ->putJson("/api/v1/events/{$this->event->id}", ['name' => 'Renamed'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_sync_batch_rejects_archived_scans_per_scan_and_records_active_ones(): void
    {
        $this->buildArchivedFixture();
        $checker = $this->actor('checker');
        $this->event->forceFill(['archived_at' => now()->subMinutes(10)])->save();

        $activeEvent = Event::factory()->for($this->organization)->create();
        $activeSession = $this->openSession($activeEvent);
        $activeRegistration = Attendee::factory()->for($activeEvent)->for($this->organization)->create();
        $archivedSecond = Attendee::factory()->for($this->event)->for($this->organization)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', [
            'scans' => [
                [
                    'client_ref' => 'active',
                    'event_id' => $activeEvent->id,
                    'session_id' => $activeSession->id,
                    'qr_token' => $activeRegistration->qr_token,
                    'method' => 'qr',
                ],
                [
                    'client_ref' => 'archived-before-archive',
                    'event_id' => $this->event->id,
                    'session_id' => $this->session->id,
                    'qr_token' => $archivedSecond->qr_token,
                    'scanned_at' => now()->subHour()->format('Y-m-d H:i:s'),
                    'method' => 'qr',
                ],
                [
                    'client_ref' => 'archived-manual',
                    'event_id' => $this->event->id,
                    'session_id' => $this->session->id,
                    'attendee_id' => $archivedSecond->id,
                    'method' => 'manual',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.client_ref', 'active')
            ->assertJsonPath('results.0.status', 'created')
            ->assertJsonPath('results.1.client_ref', 'archived-before-archive')
            ->assertJsonPath('results.1.status', 'event_archived')
            ->assertJsonPath('results.1.message', self::ARCHIVED_MESSAGE)
            ->assertJsonPath('results.1.attendance_record_id', null)
            ->assertJsonPath('results.2.status', 'event_archived');

        $this->assertDatabaseHas('attendance_records', ['attendee_id' => $activeRegistration->id]);
        $this->assertDatabaseMissing('attendance_records', ['attendee_id' => $archivedSecond->id]);
    }

    public function test_sync_batch_keeps_unauthorized_result_for_other_denials_on_archived_event(): void
    {
        $this->buildArchivedFixture();
        $outsider = $this->actor('checker');
        $outsider->accessibleEvents()->attach(Event::factory()->for($this->organization)->create());

        $this->actingAs($outsider, 'sanctum')->postJson('/api/v1/attendance/sync-batch', [
            'scans' => [[
                'client_ref' => 'outsider',
                'event_id' => $this->event->id,
                'session_id' => $this->session->id,
                'qr_token' => $this->attendee->qr_token,
                'method' => 'qr',
            ]],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.0.message', 'Unauthorized to record attendance for this event.');
    }

    public function test_reports_and_exports_of_an_archived_event_still_work(): void
    {
        $this->buildArchivedFixture();
        $admin = $this->actor('org_admin');
        $eventId = $this->event->id;
        $this->actingAs($admin, 'sanctum');

        $this->getJson("/api/v1/events/{$eventId}/reports/attendance-summary")->assertOk();
        $this->getJson("/api/v1/events/{$eventId}/reports/attendee-summary")->assertOk();
        $this->getJson("/api/v1/events/{$eventId}/reports/dashboard")->assertOk();
        $this->getJson("/api/v1/events/{$eventId}/reports/attendance-summary/export?format=xlsx")->assertOk();
        $this->getJson("/api/v1/events/{$eventId}/reports/attendee-summary/export?format=xlsx")->assertOk();
        $this->getJson("/api/v1/attendees/export?format=xlsx&event_id={$eventId}")->assertOk();
        $this->get("/api/v1/events/{$eventId}/registrations/qr-export")->assertOk();
    }

    public function test_id_card_downloads_of_an_archived_event_still_work(): void
    {
        $this->buildArchivedFixture();
        $admin = $this->actor('org_admin');
        $eventId = $this->event->id;
        $this->actingAs($admin, 'sanctum');

        $this->postJson("/api/v1/events/{$eventId}/registrations/id-cards/grid-download", [
            'registration_ids' => [$this->attendee->id],
        ])->assertStatus(202);

        $download = IdCardGridDownload::factory()->for($this->event)->create([
            'status' => IdCardGridDownloadStatus::Completed,
            'file_path' => "{$eventId}/grid.zip",
        ]);
        Storage::disk('local')->put($download->file_path, 'zip contents');
        $this->get("/api/v1/events/{$eventId}/registrations/id-cards/grid-download/{$download->id}/file")->assertOk();

        $this->attendee->forceFill(['id_card_path' => 'id-cards/archived.pdf'])->save();
        Storage::disk('local')->put('id-cards/archived.pdf', 'pdf contents');
        $this->get("/api/v1/events/{$eventId}/registrations/{$this->attendee->id}/id-card")->assertOk();
    }

    public function test_speakers_sessions_and_program_of_an_archived_event_are_readable(): void
    {
        $this->buildArchivedFixture();
        $admin = $this->actor('org_admin');
        $eventId = $this->event->id;
        $this->actingAs($admin, 'sanctum');

        $this->getJson("/api/v1/events/{$eventId}/speakers")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/events/{$eventId}/sessions")->assertOk();
        $this->getJson("/api/v1/events/{$eventId}/program")->assertOk();
    }

    public function test_super_admin_can_unarchive_an_archived_event(): void
    {
        $this->buildArchivedFixture();

        $this->actingAs($this->actor('super_admin'), 'sanctum')
            ->postJson("/api/v1/events/{$this->event->id}/unarchive")
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);

        $this->assertFalse($this->event->refresh()->isArchived());
    }

    public function test_event_access_sync_keeps_existing_archived_assignment_when_omitted(): void
    {
        $this->buildArchivedFixture();
        $admin = $this->actor('org_admin');
        $active = Event::factory()->for($this->organization)->create();
        $other = Event::factory()->for($this->organization)->create();
        $this->checkerTarget->accessibleEvents()->attach([$this->event->id, $active->id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/users/{$this->checkerTarget->id}/event-access", ['event_ids' => [$other->id]])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->event->id, $other->id],
            $this->checkerTarget->accessibleEvents()->pluck('events.id')->all(),
        );
    }

    public function test_event_access_sync_with_empty_list_restores_full_org_access(): void
    {
        $this->buildArchivedFixture();
        $this->checkerTarget->accessibleEvents()->attach($this->event->id);

        $this->actingAs($this->actor('org_admin'), 'sanctum')
            ->putJson("/api/v1/users/{$this->checkerTarget->id}/event-access", ['event_ids' => []])
            ->assertOk();

        $this->assertSame(0, $this->checkerTarget->accessibleEvents()->count());
    }

    public function test_event_access_sync_accepts_an_already_assigned_archived_event(): void
    {
        $this->buildArchivedFixture();
        $active = Event::factory()->for($this->organization)->create();
        $this->checkerTarget->accessibleEvents()->attach($this->event->id);

        $this->actingAs($this->actor('super_admin'), 'sanctum')
            ->putJson("/api/v1/users/{$this->checkerTarget->id}/event-access", ['event_ids' => [$this->event->id, $active->id]])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->event->id, $active->id],
            $this->checkerTarget->accessibleEvents()->pluck('events.id')->all(),
        );
    }

    public function test_attendee_in_an_active_event_can_be_edited_while_the_same_person_is_archived_elsewhere(): void
    {
        $this->buildArchivedFixture();
        $activeCopy = $this->registerInActiveEvent($this->attendee);

        $this->actingAs($this->actor('org_admin'), 'sanctum')
            ->putJson("/api/v1/attendees/{$activeCopy->id}", ['remarks' => 'Updated'])
            ->assertOk();
        $this->actingAs($this->actor('org_admin'), 'sanctum')
            ->putJson("/api/v1/attendees/{$this->attendee->id}", ['remarks' => 'Updated'])
            ->assertForbidden()
            ->assertExactJson(['message' => self::ARCHIVED_MESSAGE]);

        $this->assertSame('Updated', $activeCopy->refresh()->remarks);
        $this->assertNotSame('Updated', $this->attendee->refresh()->remarks);
    }

    public function test_attendee_without_an_event_can_be_edited(): void
    {
        $this->buildArchivedFixture();
        $unregistered = Attendee::factory()->for($this->organization)->create();

        $this->actingAs($this->actor('org_admin'), 'sanctum')
            ->putJson("/api/v1/attendees/{$unregistered->id}", ['remarks' => 'Updated'])
            ->assertOk();
    }

    public function test_attendee_in_an_archived_event_cannot_be_deleted_but_the_active_copy_can(): void
    {
        $this->buildArchivedFixture();
        $activeCopy = $this->registerInActiveEvent($this->attendee);
        DB::table('attendees')->where('id', $activeCopy->id)->update(['first_registered_at' => null, 'registration_completed_at' => null]);

        $this->actingAs($this->actor('org_admin'), 'sanctum')
            ->deleteJson("/api/v1/attendees/{$this->attendee->id}")
            ->assertForbidden()
            ->assertExactJson(['message' => self::ARCHIVED_MESSAGE]);
        $this->actingAs($this->actor('org_admin'), 'sanctum')
            ->deleteJson("/api/v1/attendees/{$activeCopy->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('attendees', ['id' => $this->attendee->id]);
        $this->assertDatabaseMissing('attendees', ['id' => $activeCopy->id]);
    }

    public function test_merge_is_blocked_when_a_duplicate_is_in_an_archived_event(): void
    {
        $this->buildArchivedFixture();

        $this->actingAs($this->actor('org_admin'), 'sanctum')
            ->postJson('/api/v1/attendees/duplicates/merge', [
                'primary_attendee_id' => $this->duplicate->id,
                'duplicate_attendee_ids' => [$this->attendee->id],
            ])
            ->assertForbidden()
            ->assertExactJson(['message' => self::ARCHIVED_MESSAGE]);

        $this->assertNull($this->attendee->refresh()->merged_into_id);
    }

    public function test_merge_still_works_for_attendees_of_an_active_event(): void
    {
        $this->buildArchivedFixture();
        $activeEvent = Event::factory()->for($this->organization)->create();
        $primary = Attendee::factory()->for($activeEvent)->create(['first_name' => 'Same', 'last_name' => 'Name']);
        $source = Attendee::factory()->for($activeEvent)->create(['first_name' => 'Same', 'last_name' => 'Name']);

        $this->actingAs($this->actor('org_admin'), 'sanctum')
            ->postJson('/api/v1/attendees/duplicates/merge', [
                'primary_attendee_id' => $primary->id,
                'duplicate_attendee_ids' => [$source->id],
            ])
            ->assertOk();
    }

    private function buildArchivedFixture(): void
    {
        $this->organization = Organization::factory()->create();
        $this->event = Event::factory()->for($this->organization)->create(['requires_check_out' => true]);
        $this->session = $this->openSession($this->event);
        $this->speaker = Speaker::factory()->for($this->event)->create();
        $program = EventProgram::factory()->for($this->event)->create();
        $this->item = EventProgramItem::factory()->create([
            'event_program_id' => $program->id,
            'photo_paths' => [['original' => 'event_programs/photo.webp']],
        ]);
        $this->attendee = Attendee::factory()->for($this->event)->create(['first_name' => 'Dupe', 'last_name' => 'Person']);
        $this->duplicate = Attendee::factory()->for($this->event)->create(['first_name' => 'Dupe', 'last_name' => 'Person']);
        $this->record = AttendanceRecord::factory()->create([
            'attendee_id' => $this->attendee->id,
            'event_session_id' => $this->session->id,
        ]);
        $this->checkerTarget = User::factory()->checker()->for($this->organization)->create();
        $this->event->forceFill(['archived_at' => now(), 'banner_paths' => ['original' => 'events/banner.webp']])->save();
    }

    private function openSession(Event $event): EventSession
    {
        return EventSession::factory()->for($event)->create([
            'session_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->addHours(4)->format('H:i:s'),
        ]);
    }

    private function registerInActiveEvent(Attendee $attendee): Attendee
    {
        return $this->registerInEvent(Event::factory()->for($this->organization)->create(), $attendee);
    }

    private function actor(string $role): User
    {
        return match ($role) {
            'super_admin' => User::factory()->superAdmin()->create(),
            'org_admin' => User::factory()->orgAdmin()->for($this->organization)->create(),
            'checker' => User::factory()->checker()->for($this->organization)->create(),
            'attendee' => User::factory()->attendee()->create(),
        };
    }

    /**
     * @template T of string|array<array-key, mixed>
     *
     * @param  T  $value
     * @return T
     */
    private function resolve(string|array $value): string|array
    {
        $ids = [
            ':event' => $this->event->id,
            ':invite' => $this->event->invite_token,
            ':session' => $this->session->id,
            ':speaker' => $this->speaker->id,
            ':item' => $this->item->id,
            ':attendee' => $this->attendee->id,
            ':duplicate' => $this->duplicate->id,
            ':qr' => $this->attendee->qr_token,
            ':record' => $this->record->id,
            ':checker' => $this->checkerTarget->id,
        ];

        if (is_array($value)) {
            return array_map(fn ($item) => is_array($item) || is_string($item) ? $this->resolve($item) : $item, $value);
        }

        if (array_key_exists($value, $ids)) {
            return $ids[$value];
        }

        return strtr($value, array_map('strval', $ids));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $tables = [
            'audit_logs', 'attendance_records', 'attendees', 'speakers',
            'event_sessions', 'event_programs', 'event_program_items', 'event_program_days', 'event_checker_access',
        ];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return [
            'counts' => $counts,
            'event' => (array) DB::table('events')->find($this->event->id),
            'attendee' => (array) DB::table('attendees')->find($this->attendee->id),
            'speaker' => (array) DB::table('speakers')->find($this->speaker->id),
            'session' => (array) DB::table('event_sessions')->find($this->session->id),
            'item' => (array) DB::table('event_program_items')->find($this->item->id),
            'record' => (array) DB::table('attendance_records')->find($this->record->id),
        ];
    }
}
