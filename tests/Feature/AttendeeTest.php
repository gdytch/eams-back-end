<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AttendeeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_attendee_can_be_created_with_new_contact_fields(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'mobile_no' => '+1234567890',
            'email_address' => 'john@example.com',
            'remarks' => 'VIP attendee',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attendees', [
            'first_name' => 'John',
            'mobile_no' => '+1234567890',
            'email_address' => 'john@example.com',
            'remarks' => 'VIP attendee',
        ]);
    }

    public function test_attendee_contact_fields_are_optional(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attendees', [
            'first_name' => 'Jane',
            'mobile_no' => null,
            'email_address' => null,
            'remarks' => null,
        ]);
    }

    public function test_email_address_must_be_valid(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email_address' => 'invalid-email',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email_address');
    }

    public function test_mobile_no_has_max_length(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'mobile_no' => str_repeat('1', 21),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('mobile_no');
    }

    public function test_attendee_can_be_assigned_to_church(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();
        $church = Church::factory()->for($org)->for($union)->for($mission)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'church_id' => $church->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attendees', [
            'first_name' => 'John',
            'church_id' => $church->id,
        ]);
    }

    public function test_attendee_church_relation_loads_in_resource(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();
        $church = Church::factory()->for($org)->for($union)->for($mission)->create();
        $attendee = Attendee::factory()
            ->for($org)
            ->for($union)
            ->for($mission)
            ->for($church)
            ->create(['created_by' => $checker->id]);

        $response = $this->actingAs($checker, 'sanctum')->getJson("/api/v1/attendees?search={$attendee->first_name}");

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
        $this->assertEquals($church->id, $response->json('data.0.church_id'));
        $this->assertNotNull($response->json('data.0.church'));
    }

    public function test_attendee_contact_fields_round_trip_through_update(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create(['created_by' => $checker->id]);

        $response = $this->actingAs($checker, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", [
            'mobile_no' => '+1234567890',
            'email_address' => 'updated@example.com',
            'remarks' => 'Updated remarks',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.mobile_no', '+1234567890');
        $response->assertJsonPath('data.email_address', 'updated@example.com');
        $response->assertJsonPath('data.remarks', 'Updated remarks');
    }

    public function test_church_id_must_belong_to_same_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $checkerA = User::factory()->checker()->for($orgA)->create();
        $unionB = Union::factory()->for($orgB)->create();
        $missionB = Mission::factory()->for($orgB)->for($unionB)->create();
        $churchB = Church::factory()->for($orgB)->for($unionB)->for($missionB)->create();

        $response = $this->actingAs($checkerA, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'church_id' => $churchB->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('church_id');
    }

    public function test_attendee_can_update_own_profile_fields(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create(['role' => 'attendee']);
        $attendee = Attendee::factory()->for($org)->for($user)->create([
            'mobile_no' => '555-0000',
            'email_address' => 'old@example.com',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", [
            'mobile_no' => '555-1234',
            'email_address' => 'new@example.com',
            'remarks' => 'Updated info',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.mobile_no', '555-1234');
        $response->assertJsonPath('data.email_address', 'new@example.com');
        $response->assertJsonPath('data.remarks', 'Updated info');
    }

    public function test_attendee_cannot_change_own_first_name(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create(['role' => 'attendee']);
        $attendee = Attendee::factory()->for($org)->for($user)->create([
            'first_name' => 'John',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", [
            'first_name' => 'Jane',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('first_name');
    }

    public function test_attendee_cannot_change_own_last_name(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create(['role' => 'attendee']);
        $attendee = Attendee::factory()->for($org)->for($user)->create([
            'last_name' => 'Doe',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", [
            'last_name' => 'Smith',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('last_name');
    }

    public function test_attendee_cannot_update_another_attendee(): void
    {
        $org = Organization::factory()->create();
        $user1 = User::factory()->for($org)->create(['role' => 'attendee']);
        $user2 = User::factory()->for($org)->create(['role' => 'attendee']);
        $attendee1 = Attendee::factory()->for($org)->for($user1)->create();
        $attendee2 = Attendee::factory()->for($org)->for($user2)->create();

        $response = $this->actingAs($user1, 'sanctum')->putJson("/api/v1/attendees/{$attendee2->id}", [
            'mobile_no' => '555-9999',
        ]);

        $response->assertForbidden();
    }

    public function test_staff_can_change_attendee_name_fields(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.first_name', 'Jane');
        $response->assertJsonPath('data.last_name', 'Smith');
    }

    public function test_attendee_can_set_church_via_update(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create(['role' => 'attendee']);
        $attendee = Attendee::factory()->for($org)->for($user)->create([
            'church_id' => null,
        ]);
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();
        $church = Church::factory()->for($org)->for($union)->for($mission)->create();

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", [
            'church_id' => $church->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.church_id', $church->id);
    }

    public function test_attendee_name_update_syncs_to_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create([
            'role' => 'attendee',
            'first_name' => 'John',
            'middle_name' => 'Q',
            'last_name' => 'Doe',
        ]);
        $attendee = Attendee::factory()->for($org)->for($user)->create([
            'first_name' => 'John',
            'middle_name' => 'Q',
            'last_name' => 'Doe',
        ]);

        // Admin updates attendee's name
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $response->assertOk();

        // Verify attendee name changed
        $attendee->refresh();
        $this->assertEquals('Jane', $attendee->first_name);
        $this->assertEquals('Smith', $attendee->last_name);

        // Verify user name also changed
        $user->refresh();
        $this->assertEquals('Jane', $user->first_name);
        $this->assertEquals('Smith', $user->last_name);
        $this->assertStringContainsString('Jane', $user->name);
        $this->assertStringContainsString('Smith', $user->name);
    }

    public function test_attendee_middle_name_update_syncs_to_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create([
            'role' => 'attendee',
            'middle_name' => 'Q',
        ]);
        $attendee = Attendee::factory()->for($org)->for($user)->create([
            'middle_name' => 'Q',
        ]);

        $admin = User::factory()->orgAdmin()->for($org)->create();
        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", [
            'middle_name' => 'Alexander',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertEquals('Alexander', $user->middle_name);
    }

    public function test_show_returns_attendee_without_event_stats_by_default(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create(['created_by' => $checker->id]);

        $response = $this->actingAs($checker, 'sanctum')->getJson("/api/v1/attendees/{$attendee->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $attendee->id);
        $response->assertJsonPath('event_stats', null);
        $response->assertJsonMissingPath('profile_dashboard');
    }

    public function test_profile_dashboard_shows_account_and_completed_session_attendance(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $account = User::factory()->attendee()->unverified()->create();
        $attendee = Attendee::factory()->for($org)->for($account, 'user')->create();
        $event = Event::factory()->for($org)->create();
        $registration = EventRegistration::factory()->for($event)->for($attendee)->create();
        $attended = EventSession::factory()->for($event)->create(['session_date' => now()->subDays(2)]);
        EventSession::factory()->for($event)->create(['session_date' => now()->subDay()]);
        EventSession::factory()->for($event)->create(['session_date' => now()->addDay()]);
        AttendanceRecord::factory()->for($registration)->for($attended, 'eventSession')
            ->create(['check_in_at' => now()->subDays(2)]);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/attendees/{$attendee->id}?include=dashboard");

        $response->assertOk()
            ->assertJsonPath('profile_dashboard.account.linked', true)
            ->assertJsonPath('profile_dashboard.account.email', $account->email)
            ->assertJsonPath('profile_dashboard.account.email_verified', false)
            ->assertJsonPath('profile_dashboard.stats.registration_count', 1)
            ->assertJsonPath('profile_dashboard.stats.completed_sessions', 2)
            ->assertJsonPath('profile_dashboard.stats.attended_sessions', 1)
            ->assertJsonPath('profile_dashboard.stats.attendance_rate', 50)
            ->assertJsonPath('profile_dashboard.registrations.0.event.id', $event->id)
            ->assertJsonPath('profile_dashboard.registrations.0.attendance.sessions_total', 3)
            ->assertJsonPath('profile_dashboard.registrations.0.attendance.rate', 50)
            ->assertJsonMissingPath('profile_dashboard.registrations.0.qr_token');
    }

    public function test_profile_dashboard_empty_state_and_pending_invitation(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create(['invite_token' => 'pending-invite']);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/attendees/{$attendee->id}?include=dashboard");

        $response->assertOk()
            ->assertJsonPath('profile_dashboard.account.linked', false)
            ->assertJsonPath('profile_dashboard.account.invite_status', 'pending')
            ->assertJsonPath('profile_dashboard.registrations', [])
            ->assertJsonPath('profile_dashboard.stats.attendance_rate', null);
    }

    public function test_profile_dashboard_only_shows_events_the_viewer_can_access(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create();
        $allowed = Event::factory()->for($org)->create();
        $restricted = Event::factory()->for($org)->create();
        $foreign = Event::factory()->for($otherOrg)->create();
        $checker->accessibleEvents()->sync([$allowed->id]);
        EventRegistration::factory()->for($allowed)->for($attendee)->create();
        EventRegistration::factory()->for($restricted)->for($attendee)->create();
        EventRegistration::factory()->for($foreign)->for($attendee)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/attendees/{$attendee->id}?include=dashboard");

        $response->assertOk()
            ->assertJsonPath('profile_dashboard.stats.registration_count', 1)
            ->assertJsonCount(1, 'profile_dashboard.registrations')
            ->assertJsonPath('profile_dashboard.registrations.0.event.id', $allowed->id);
    }

    public function test_profile_dashboard_rejects_attendee_from_another_organization(): void
    {
        $viewerOrg = Organization::factory()->create();
        $attendeeOrg = Organization::factory()->create();
        $checker = User::factory()->checker()->for($viewerOrg)->create();
        $attendee = Attendee::factory()->for($attendeeOrg)->create();

        $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/attendees/{$attendee->id}?include=dashboard")
            ->assertNotFound();
    }

    public function test_show_returns_null_event_stats_for_unregistered_attendee(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create(['created_by' => $checker->id]);
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->getJson("/api/v1/attendees/{$attendee->id}?event_id={$event->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $attendee->id);
        $response->assertJsonPath('event_stats', null);
    }

    public function test_show_returns_event_stats_for_registered_attendee(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create(['created_by' => $checker->id]);
        $event = Event::factory()->for($org)->create();

        // Register attendee to event
        $registration = EventRegistration::factory()
            ->for($event)
            ->for($attendee)
            ->create(['registered_by' => $checker->id]);

        // Create sessions (1 past with check-in, 1 past without check-in, 1 future)
        $pastSessionWithCheckin = EventSession::factory()
            ->for($event)
            ->create(['session_date' => now()->subDays(2)]);
        $pastSessionWithoutCheckin = EventSession::factory()
            ->for($event)
            ->create(['session_date' => now()->subDays(1)]);
        $futureSession = EventSession::factory()
            ->for($event)
            ->create(['session_date' => now()->addDays(1)]);

        // Add check-in for first past session only
        AttendanceRecord::factory()
            ->for($registration)
            ->for($pastSessionWithCheckin, 'eventSession')
            ->create(['check_in_at' => now()->subDays(2)]);

        $response = $this->actingAs($checker, 'sanctum')->getJson("/api/v1/attendees/{$attendee->id}?event_id={$event->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $attendee->id);
        $response->assertJsonPath('event_stats.registered', true);
        $response->assertJsonPath('event_stats.present_count', 1);
        $response->assertJsonPath('event_stats.absent_count', 1);
        $response->assertJsonPath('event_stats.total_sessions', 3);
        $response->assertJsonPath('event_stats.registration_count', 1);
    }

    public function test_show_event_stats_only_counts_sessions_with_past_end_time(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create(['created_by' => $checker->id]);
        $event = Event::factory()->for($org)->create();

        $registration = EventRegistration::factory()
            ->for($event)
            ->for($attendee)
            ->create(['registered_by' => $checker->id]);

        // Create 3 sessions: past, current/future, future
        $pastSession = EventSession::factory()
            ->for($event)
            ->create(['session_date' => now()->subDays(1)]);
        $futureSession = EventSession::factory()
            ->for($event)
            ->create(['session_date' => now()->addDays(1)]);
        $farFutureSession = EventSession::factory()
            ->for($event)
            ->create(['session_date' => now()->addDays(2)]);

        // No check-in for any session
        $response = $this->actingAs($checker, 'sanctum')->getJson("/api/v1/attendees/{$attendee->id}?event_id={$event->id}");

        $response->assertOk();
        // Only past session should count toward absent (present=0, absent=1, future sessions excluded)
        $response->assertJsonPath('event_stats.present_count', 0);
        $response->assertJsonPath('event_stats.absent_count', 1);
        $response->assertJsonPath('event_stats.total_sessions', 3);
    }

    public function test_show_registration_count_includes_all_events(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create(['created_by' => $checker->id]);

        $event1 = Event::factory()->for($org)->create();
        $event2 = Event::factory()->for($org)->create();
        $event3 = Event::factory()->for($org)->create();

        // Register to all 3 events
        EventRegistration::factory()->for($event1)->for($attendee)->create(['registered_by' => $checker->id]);
        EventRegistration::factory()->for($event2)->for($attendee)->create(['registered_by' => $checker->id]);
        EventRegistration::factory()->for($event3)->for($attendee)->create(['registered_by' => $checker->id]);

        $response = $this->actingAs($checker, 'sanctum')->getJson("/api/v1/attendees/{$attendee->id}?event_id={$event1->id}");

        $response->assertOk();
        $response->assertJsonPath('event_stats.registration_count', 3);
    }

    public function test_attendee_can_be_auto_registered_to_an_event_on_creation(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'auto_register' => true,
            'event_id' => $event->id,
        ]);

        $response->assertCreated();
        $attendeeId = $response->json('data.id');
        $response->assertJsonPath('registration.event_id', $event->id);
        $response->assertJsonPath('registration.attendee_id', $attendeeId);
        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'attendee_id' => $attendeeId,
        ]);
    }

    public function test_attendee_is_not_registered_when_auto_register_is_not_requested(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        Event::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('registration', null);
        $this->assertDatabaseCount('event_registrations', 0);
    }

    public function test_auto_register_requires_an_event_id(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'auto_register' => true,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('event_id');
    }

    public function test_auto_register_event_must_belong_to_the_attendees_organization(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($otherOrg)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'auto_register' => true,
            'event_id' => $event->id,
        ]);

        // Checker lacks access to an event outside their organization, so authorization fails first.
        $response->assertForbidden();
        $this->assertDatabaseCount('event_registrations', 0);
    }
}
