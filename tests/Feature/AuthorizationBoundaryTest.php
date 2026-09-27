<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthorizationBoundaryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unassigned_checker_keeps_full_organization_access(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        Event::factory()->for($org)->create();

        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_checker_event_list_contains_only_assigned_events(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $assigned = Event::factory()->for($org)->create();
        $unassigned = Event::factory()->for($org)->create();
        $checker->accessibleEvents()->attach($assigned);

        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('data.0.id', $assigned->id)
            ->assertJsonMissing(['id' => $unassigned->id]);
    }

    public function test_attendee_cannot_browse_other_attendees(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $mine = Attendee::factory()->for($org)->for($user)->create();
        $other = Attendee::factory()->for($org)->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/attendees/{$other->id}")->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/attendees/{$mine->id}")->assertOk();
    }

    public function test_attendee_cannot_list_event_roster_registrations(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($user)->create();
        $event = Event::factory()->for($org)->create();
        EventRegistration::factory()->for($event)->for($attendee)->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/events/{$event->id}/registrations")
            ->assertForbidden();
    }

    public function test_attendee_cannot_read_attendance_record(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($user)->create();
        $event = Event::factory()->for($org)->create();
        $registration = EventRegistration::factory()->for($event)->for($attendee)->create();
        $session = $event->sessions()->create([
            'name' => 'Session',
            'session_date' => now()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);
        $record = $registration->attendanceRecords()->create([
            'event_session_id' => $session->id,
            'check_in_at' => now(),
            'attendance_method' => 'manual',
        ]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/attendance/{$record->id}/check-out")
            ->assertForbidden();
    }

    public function test_attendee_can_download_own_card_for_cross_organization_event(): void
    {
        $attendeeOrg = Organization::factory()->create();
        $eventOrg = Organization::factory()->create();
        $user = User::factory()->attendee()->create(['organization_id' => $attendeeOrg->id]);
        $attendee = Attendee::factory()->for($attendeeOrg)->for($user)->create();
        $event = Event::factory()->for($eventOrg)->create();
        $registration = EventRegistration::factory()->for($event)->for($attendee)->create();

        $this->assertTrue($user->can('downloadIdCard', $registration));
        $this->assertTrue($user->can('viewIdCardImage', $registration));
        $this->assertFalse($user->can('manageIdCards', $registration));
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/events/{$event->id}/registrations/{$registration->id}/id-card")->assertStatus(202);
        $this->getJson("/api/v1/events/{$event->id}/registrations/{$registration->id}/id-card-image")->assertStatus(202);
        $this->getJson("/api/v1/events/999999/registrations/{$registration->id}/id-card")->assertNotFound();
    }

    public function test_org_admin_can_assign_checker_only_same_organization_events(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $foreignEvent = Event::factory()->for($otherOrg)->create();

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$checker->id}/event-access", [
            'event_ids' => [$event->id, $foreignEvent->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('event_ids.1');

        $this->assertDatabaseMissing('event_checker_access', ['user_id' => $checker->id]);
    }

    public function test_attendee_cannot_read_staff_reports_or_modify_registrations(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($user)->create();
        $event = Event::factory()->for($org)->create();
        EventRegistration::factory()->for($event)->for($attendee)->create();
        $this->actingAs($user, 'sanctum');

        $this->getJson("/api/v1/events/{$event->id}/reports/dashboard")->assertForbidden();
        $this->postJson("/api/v1/events/{$event->id}/registrations", ['attendee_id' => $attendee->id])->assertForbidden();
    }

    public function test_staff_cannot_create_records_in_another_organization(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $this->actingAs($admin, 'sanctum');

        foreach (['users', 'users/invite', 'events', 'attendees', 'unions', 'missions', 'churches'] as $endpoint) {
            $this->postJson('/api/v1/'.$endpoint, ['organization_id' => $other->id])
                ->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        }
    }
}
