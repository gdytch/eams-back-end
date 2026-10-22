<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\IdCardGridDownload;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ArchivedEventReadPathsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_invite_of_an_archived_event_returns_the_closed_state(): void
    {
        $event = Event::factory()->create(['status' => EventStatus::Published, 'archived_at' => now()]);

        $response = $this->getJson("/api/v1/public/event/{$event->invite_token}");

        $response->assertOk()
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.name', $event->name)
            ->assertJsonPath('data.closed', true);
    }

    public function test_public_invite_of_an_active_event_is_not_closed(): void
    {
        $event = Event::factory()->create(['status' => EventStatus::Published]);

        $this->getJson("/api/v1/public/event/{$event->invite_token}")
            ->assertOk()
            ->assertJsonPath('data.closed', false);
    }

    public function test_public_invite_of_an_archived_event_in_any_non_draft_status_returns_the_closed_state(): void
    {
        foreach ([EventStatus::Completed, EventStatus::Cancelled] as $status) {
            $event = Event::factory()->create(['status' => $status, 'archived_at' => now()]);

            $this->getJson("/api/v1/public/event/{$event->invite_token}")
                ->assertOk()
                ->assertJsonPath('data.id', $event->id)
                ->assertJsonPath('data.closed', true);
        }
    }

    public function test_public_invite_of_an_archived_draft_event_is_not_found(): void
    {
        $event = Event::factory()->create(['status' => EventStatus::Draft, 'archived_at' => now()]);

        $this->getJson("/api/v1/public/event/{$event->invite_token}")->assertNotFound();
    }

    public function test_public_invite_of_a_non_archived_unpublished_event_is_still_not_found(): void
    {
        $event = Event::factory()->create(['status' => EventStatus::Completed]);

        $this->getJson("/api/v1/public/event/{$event->invite_token}")->assertNotFound();
    }

    public function test_self_registration_to_an_archived_unpublished_event_is_not_found(): void
    {
        $event = Event::factory()->create(['status' => EventStatus::Completed, 'archived_at' => now()]);
        $user = User::factory()->attendee()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/event/{$event->invite_token}/register")
            ->assertNotFound();
    }

    public function test_self_registration_to_an_archived_event_is_refused(): void
    {
        Bus::fake();
        $event = Event::factory()->create(['status' => EventStatus::Published, 'archived_at' => now()]);
        $user = User::factory()->attendee()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/event/{$event->invite_token}/register");

        $response->assertForbidden()->assertJsonPath('message', 'This event is archived.');
        $this->assertDatabaseMissing('event_registrations', ['event_id' => $event->id]);
        $this->assertDatabaseMissing('attendees', ['user_id' => $user->id]);
    }

    public function test_attendee_with_only_archived_events_can_log_in_and_load_the_dashboard(): void
    {
        [$user, $registration, $event] = $this->attendeeRegisteredToArchivedUpcomingEvent();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendee/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.has_upcoming_events', false)
            ->assertJsonPath('data.upcoming_events', [])
            ->assertJsonPath('data.next_event', null)
            ->assertJsonCount(1, 'data.past_events')
            ->assertJsonPath('data.past_events.0.registration_id', $registration->id)
            ->assertJsonPath('data.past_events.0.event.id', $event->id)
            ->assertJsonPath('data.past_events.0.archived', true)
            ->assertJsonPath('data.past_events.0.qr_token', $registration->qr_token)
            ->assertJsonPath('data.past_events.0.id_card_ready', false)
            ->assertJsonPath('data.past_events.0.id_card_url', "/api/v1/events/{$event->id}/registrations/{$registration->id}/id-card")
            ->assertJsonPath('data.past_events.0.attendance_summary.sessions_total', 1)
            ->assertJsonPath('data.past_events.0.attendance_summary.sessions_attended', 1)
            ->assertJsonPath('data.latest_event.archived', true)
            ->assertJsonPath('data.stats.total_upcoming_events', 0)
            ->assertJsonPath('data.stats.total_past_events', 1);
    }

    public function test_dashboard_flags_upcoming_events_and_marks_active_entries_as_not_archived(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($user)->create();
        $upcoming = Event::factory()->for($org)->create(['start_date' => now()->addDays(3), 'end_date' => now()->addDays(4)]);
        $past = Event::factory()->for($org)->create(['start_date' => now()->subDays(9), 'end_date' => now()->subDays(8)]);
        EventRegistration::factory()->for($upcoming)->for($attendee)->create();
        EventRegistration::factory()->for($past)->for($attendee)->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendee/dashboard')
            ->assertOk()
            ->assertJsonPath('data.has_upcoming_events', true)
            ->assertJsonPath('data.upcoming_events.0.archived', false)
            ->assertJsonPath('data.past_events.0.archived', false)
            ->assertJsonPath('data.next_event.archived', false);
    }

    public function test_empty_dashboard_has_no_upcoming_events(): void
    {
        $user = User::factory()->attendee()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendee/dashboard')
            ->assertOk()
            ->assertJsonPath('data.has_upcoming_events', false);
    }

    public function test_attendee_can_still_read_an_archived_event(): void
    {
        [$user, $registration, $event, $session] = $this->attendeeRegisteredToArchivedUpcomingEvent();
        EventProgram::factory()->for($event)->create();
        Speaker::factory()->for($event)->create();

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/events/{$event->id}/registrations/{$registration->id}/id-card")
            ->assertStatus(202);
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/attendee/events/{$event->id}/attendance")
            ->assertOk()
            ->assertJsonPath('registration_id', $registration->id)
            ->assertJsonPath('sessions.0.id', $session->id)
            ->assertJsonPath('sessions.0.status', 'present');
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/attendee/registrations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $registration->id);
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/attendee/events/{$event->id}/program")
            ->assertOk();
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/attendee/events/{$event->id}/speakers")
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertTrue($user->can('view', $event));
    }

    public function test_checker_assigned_to_an_archived_event_does_not_see_it(): void
    {
        [$checker, $event, $session] = $this->checkerAssignedToEvent();
        $active = Event::factory()->for($event->organization)->create();
        $checker->accessibleEvents()->attach($active);
        $event->forceFill(['archived_at' => now()])->save();

        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$active->id]);
        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/events?archived=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        foreach ($this->checkerReadPaths($event, $session) as $path) {
            $this->actingAs($checker, 'sanctum')->getJson($path)->assertNotFound();
        }
        $this->actingAs($checker, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/registrations/id-cards/grid-download-all")
            ->assertNotFound();

        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/search?q='.urlencode($event->name).'&category=events')
            ->assertOk()
            ->assertJsonPath('groups.0.total', 0);
        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('accessible_events_count', 1);
        $this->assertDatabaseHas('event_checker_access', ['user_id' => $checker->id, 'event_id' => $event->id]);
    }

    public function test_checker_without_assignments_does_not_see_archived_events(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create(['archived_at' => now()]);
        $session = EventSession::factory()->for($event)->create();

        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/events?archived=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($checker, 'sanctum')->getJson("/api/v1/events/{$event->id}")->assertNotFound();
        $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/sessions/{$session->id}/roster")
            ->assertNotFound();
    }

    public function test_checker_sees_the_event_again_after_unarchive(): void
    {
        [$checker, $event, $session] = $this->checkerAssignedToEvent();
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/events/{$event->id}/archive")->assertOk();
        $this->actingAs($checker, 'sanctum')->getJson("/api/v1/events/{$event->id}")->assertNotFound();

        $this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/events/{$event->id}/unarchive")->assertOk();

        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$event->id]);
        foreach ($this->checkerReadPaths($event, $session) as $path) {
            $this->actingAs($checker, 'sanctum')->getJson($path)->assertSuccessful();
        }
        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/search?q='.urlencode($event->name).'&category=events')
            ->assertOk()
            ->assertJsonPath('groups.0.total', 1);
        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('accessible_events_count', 1);
    }

    public function test_org_admin_and_super_admin_keep_read_access_to_archived_events(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create(['archived_at' => now()]);
        $session = EventSession::factory()->for($event)->create();
        EventProgram::factory()->for($event)->create();
        EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();

        foreach ([User::factory()->orgAdmin()->for($org)->create(), User::factory()->superAdmin()->create()] as $user) {
            foreach ($this->checkerReadPaths($event, $session) as $path) {
                $this->actingAs($user, 'sanctum')->getJson($path)->assertSuccessful();
            }
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/events?archived=1')
                ->assertOk()
                ->assertJsonPath('data.*.id', [$event->id]);
        }
    }

    public function test_checker_dashboard_leaves_out_scans_on_archived_events(): void
    {
        [$checker, $event, $session] = $this->checkerAssignedToEvent();
        $active = Event::factory()->for($event->organization)->create();
        $activeSession = EventSession::factory()->for($active)->create();
        $activeRegistration = EventRegistration::factory()->for($active)->for(Attendee::factory()->for($event->organization))->create();
        $checker->accessibleEvents()->attach($active);
        AttendanceRecord::factory()->for($event->registrations()->first())->for($session, 'eventSession')->create(['recorded_by' => $checker->id]);
        $activeScan = AttendanceRecord::factory()->for($activeRegistration)->for($activeSession, 'eventSession')->create(['recorded_by' => $checker->id]);
        $event->forceFill(['archived_at' => now()])->save();

        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'recent_scans')
            ->assertJsonPath('recent_scans.0.id', $activeScan->id)
            ->assertJsonPath('recent_scans.0.event_id', $active->id)
            ->assertJsonPath('my_scan_stats.today', 1)
            ->assertJsonPath('my_scan_stats.total', 1);
    }

    public function test_archived_event_is_hidden_from_a_checker_before_registration_ownership_is_checked(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create(['archived_at' => now()]);
        $own = EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org)->for($checker))->create();

        $this->actingAs($checker, 'sanctum')
            ->get("/api/v1/events/{$event->id}/registrations/{$own->id}/id-card")
            ->assertNotFound();
        $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/registrations/{$own->id}/id-card-image")
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: EventRegistration, 2: Event, 3: EventSession}
     */
    private function attendeeRegisteredToArchivedUpcomingEvent(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($user)->create();
        $event = Event::factory()->for($org)->create([
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(6),
            'archived_at' => now(),
        ]);
        $session = EventSession::factory()->for($event)->create();
        $registration = EventRegistration::factory()->for($event)->for($attendee)->create();
        AttendanceRecord::factory()->for($registration)->for($session, 'eventSession')->create();

        return [$user, $registration, $event, $session];
    }

    /**
     * @return array{0: User, 1: Event, 2: EventSession}
     */
    private function checkerAssignedToEvent(): array
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create();
        EventProgram::factory()->for($event)->create();
        EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();
        $checker->accessibleEvents()->attach($event);

        return [$checker, $event, $session];
    }

    /**
     * @return list<string>
     */
    private function checkerReadPaths(Event $event, EventSession $session): array
    {
        $registrationId = $event->registrations()->value('id');
        $gridDownloadId = IdCardGridDownload::query()->where('event_id', $event->id)->value('id')
            ?? IdCardGridDownload::factory()->for($event)->create()->id;

        return [
            "/api/v1/events/{$event->id}",
            "/api/v1/events/{$event->id}/sessions",
            "/api/v1/events/{$event->id}/sessions/{$session->id}",
            "/api/v1/events/{$event->id}/sessions/{$session->id}/roster",
            "/api/v1/events/{$event->id}/sessions/{$session->id}/attendance",
            "/api/v1/events/{$event->id}/sessions/{$session->id}/quick-stats",
            "/api/v1/events/{$event->id}/registrations",
            "/api/v1/events/{$event->id}/registrations/{$registrationId}",
            "/api/v1/events/{$event->id}/registrations/{$registrationId}/id-card",
            "/api/v1/events/{$event->id}/registrations/{$registrationId}/id-card-image",
            "/api/v1/events/{$event->id}/registrations/id-cards/bulk-download?registration_ids[]={$registrationId}",
            "/api/v1/events/{$event->id}/registrations/id-cards/download-all",
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download/{$gridDownloadId}",
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download/{$gridDownloadId}/file",
            "/api/v1/events/{$event->id}/program",
            "/api/v1/events/{$event->id}/program/items",
        ];
    }
}
