<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AttendeeDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Extract data from response, unwrapping the data key if present.
     */
    private function extractResponseData(array $response): array
    {
        return $response['data'] ?? $response;
    }

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->getJson('/api/v1/attendee/dashboard');

        $response->assertUnauthorized();
    }

    public function test_non_attendee_user_cannot_access_dashboard(): void
    {
        $checker = User::factory()->checker()->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertForbidden();
    }

    public function test_attendee_with_no_linked_attendee_record_gets_empty_state(): void
    {
        $attendeeUser = User::factory()->attendee()->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $response->json();

        // Debug: Check what keys are in the response
        $this->assertIsArray($data, 'Response is not an array: '.json_encode($data));

        // Check if response has data wrapper
        if (isset($data['data'])) {
            $data = $data['data'];
        }

        $this->assertNull($data['profile']);

        $this->assertNull($data['profile']);
        $this->assertNotNull($data['account']);
        $this->assertEquals($attendeeUser->email, $data['account']['email']);
        $this->assertEmpty($data['upcoming_events']);
        $this->assertEmpty($data['past_events']);
        $this->assertEquals(0, $data['stats']['total_events_registered']);
        $this->assertEquals(0, $data['stats']['total_sessions_attended']);
    }

    public function test_attendee_sees_upcoming_event_with_next_session(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $futureEvent = Event::factory()
            ->for($org)
            ->state([
                'start_date' => now()->addDays(10),
                'end_date' => now()->addDays(15),
            ])
            ->create();

        $session1 = EventSession::factory()
            ->for($futureEvent)
            ->state(['session_date' => now()->addDays(10)])
            ->create();

        $session2 = EventSession::factory()
            ->for($futureEvent)
            ->state(['session_date' => now()->addDays(11)])
            ->create();

        $registration = EventRegistration::factory()
            ->for($futureEvent)
            ->for($attendee)
            ->state(['id_card_generated_at' => now()])
            ->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertNotNull($data['profile']);
        $this->assertEquals($attendee->id, $data['profile']['id']);
        $this->assertCount(1, $data['upcoming_events']);
        $this->assertEmpty($data['past_events']);

        $upcomingEvent = $data['upcoming_events'][0];
        $this->assertEquals($registration->id, $upcomingEvent['registration_id']);
        $this->assertEquals($futureEvent->id, $upcomingEvent['event']['id']);
        $this->assertTrue($upcomingEvent['id_card_ready']);
        $this->assertStringContainsString('/id-card', $upcomingEvent['id_card_url']);
        $this->assertNotNull($upcomingEvent['next_session']);
        $this->assertEquals($session1->id, $upcomingEvent['next_session']['id']);
    }

    public function test_attendee_sees_past_event_with_attendance_summary(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $pastEvent = Event::factory()
            ->for($org)
            ->state([
                'start_date' => now()->subDays(10),
                'end_date' => now()->subDays(5),
            ])
            ->create();

        $session1 = EventSession::factory()->for($pastEvent)->create();
        $session2 = EventSession::factory()->for($pastEvent)->create();

        $registration = EventRegistration::factory()
            ->for($pastEvent)
            ->for($attendee)
            ->create();

        // Create attendance records for 1 of the 2 sessions
        AttendanceRecord::factory()
            ->for($registration)
            ->for($session1, 'eventSession')
            ->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertEmpty($data['upcoming_events']);
        $this->assertCount(1, $data['past_events']);

        $pastEventData = $data['past_events'][0];
        $this->assertEquals($registration->id, $pastEventData['registration_id']);
        $this->assertEquals($pastEvent->id, $pastEventData['event']['id']);
        $this->assertEquals(2, $pastEventData['attendance_summary']['sessions_total']);
        $this->assertEquals(1, $pastEventData['attendance_summary']['sessions_attended']);
    }

    public function test_attendee_with_multiple_registrations_sees_all_correctly_sorted(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        // Create multiple events: 2 upcoming, 2 past
        $upcoming1 = Event::factory()
            ->for($org)
            ->state(['start_date' => now()->addDays(5), 'end_date' => now()->addDays(6)])
            ->create();

        $upcoming2 = Event::factory()
            ->for($org)
            ->state(['start_date' => now()->addDays(10), 'end_date' => now()->addDays(12)])
            ->create();

        $past1 = Event::factory()
            ->for($org)
            ->state(['start_date' => now()->subDays(20), 'end_date' => now()->subDays(15)])
            ->create();

        $past2 = Event::factory()
            ->for($org)
            ->state(['start_date' => now()->subDays(5), 'end_date' => now()->subDays(1)])
            ->create();

        EventRegistration::factory()->for($upcoming1)->for($attendee)->create();
        EventRegistration::factory()->for($upcoming2)->for($attendee)->create();
        EventRegistration::factory()->for($past1)->for($attendee)->create();
        EventRegistration::factory()->for($past2)->for($attendee)->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertCount(2, $data['upcoming_events']);
        $this->assertCount(2, $data['past_events']);
        $this->assertEquals(4, $data['stats']['total_events_registered']);
    }

    public function test_attendee_does_not_see_other_attendees_registrations(): void
    {
        $org = Organization::factory()->create();

        $attendee1User = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee1 = Attendee::factory()->for($org)->for($attendee1User)->create();

        $attendee2User = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee2 = Attendee::factory()->for($org)->for($attendee2User)->create();

        $event = Event::factory()
            ->for($org)
            ->state(['start_date' => now()->addDays(5), 'end_date' => now()->addDays(10)])
            ->create();

        EventRegistration::factory()->for($event)->for($attendee1)->create();
        EventRegistration::factory()->for($event)->for($attendee2)->create();

        $response = $this->actingAs($attendee1User, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertCount(1, $data['upcoming_events']);
        $this->assertEquals($attendee1->id, $data['profile']['id']);
    }

    public function test_stats_correctly_sum_total_sessions_attended(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $event1 = Event::factory()
            ->for($org)
            ->state(['start_date' => now()->subDays(10), 'end_date' => now()->subDays(5)])
            ->create();

        $event2 = Event::factory()
            ->for($org)
            ->state(['start_date' => now()->subDays(4), 'end_date' => now()->subDays(1)])
            ->create();

        $reg1 = EventRegistration::factory()->for($event1)->for($attendee)->create();
        $reg2 = EventRegistration::factory()->for($event2)->for($attendee)->create();

        // Create sessions and attendance records
        $session1 = EventSession::factory()->for($event1)->create();
        $session2 = EventSession::factory()->for($event1)->create();
        AttendanceRecord::factory()->for($reg1)->for($session1, 'eventSession')->create();
        AttendanceRecord::factory()->for($reg1)->for($session2, 'eventSession')->create();

        $session3 = EventSession::factory()->for($event2)->create();
        AttendanceRecord::factory()->for($reg2)->for($session3, 'eventSession')->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertEquals(2, $data['stats']['total_events_registered']);
        $this->assertEquals(3, $data['stats']['total_sessions_attended']);
    }

    public function test_event_with_no_id_card_yet_shows_id_card_not_ready(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $event = Event::factory()
            ->for($org)
            ->state(['start_date' => now()->addDays(5), 'end_date' => now()->addDays(10)])
            ->create();

        EventRegistration::factory()
            ->for($event)
            ->for($attendee)
            ->state(['id_card_generated_at' => null])
            ->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertFalse($data['upcoming_events'][0]['id_card_ready']);
    }

    public function test_today_is_treated_as_upcoming(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $event = Event::factory()
            ->for($org)
            ->state(['start_date' => now(), 'end_date' => now()])
            ->create();

        EventRegistration::factory()->for($event)->for($attendee)->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertCount(1, $data['upcoming_events']);
        $this->assertEmpty($data['past_events']);
    }

    public function test_upcoming_events_are_sorted_ascending_by_start_date(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $event1 = Event::factory()->for($org)->state(['start_date' => now()->addDays(10), 'end_date' => now()->addDays(15)])->create();
        $event2 = Event::factory()->for($org)->state(['start_date' => now()->addDays(5), 'end_date' => now()->addDays(8)])->create();
        $event3 = Event::factory()->for($org)->state(['start_date' => now()->addDays(20), 'end_date' => now()->addDays(25)])->create();

        EventRegistration::factory()->for($event1)->for($attendee)->create();
        EventRegistration::factory()->for($event2)->for($attendee)->create();
        EventRegistration::factory()->for($event3)->for($attendee)->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertCount(3, $data['upcoming_events']);
        $this->assertEquals($event2->id, $data['upcoming_events'][0]['event']['id']);
        $this->assertEquals($event1->id, $data['upcoming_events'][1]['event']['id']);
        $this->assertEquals($event3->id, $data['upcoming_events'][2]['event']['id']);
    }

    public function test_past_events_are_sorted_descending_by_end_date(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $event1 = Event::factory()->for($org)->state(['start_date' => now()->subDays(15), 'end_date' => now()->subDays(10)])->create();
        $event2 = Event::factory()->for($org)->state(['start_date' => now()->subDays(8), 'end_date' => now()->subDays(1)])->create();
        $event3 = Event::factory()->for($org)->state(['start_date' => now()->subDays(25), 'end_date' => now()->subDays(20)])->create();

        EventRegistration::factory()->for($event1)->for($attendee)->create();
        EventRegistration::factory()->for($event2)->for($attendee)->create();
        EventRegistration::factory()->for($event3)->for($attendee)->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertCount(3, $data['past_events']);
        $this->assertEquals($event2->id, $data['past_events'][0]['event']['id']);
        $this->assertEquals($event1->id, $data['past_events'][1]['event']['id']);
        $this->assertEquals($event3->id, $data['past_events'][2]['event']['id']);
    }

    public function test_next_event_spotlight_is_first_upcoming_event(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $event1 = Event::factory()->for($org)->state(['start_date' => now()->addDays(5), 'end_date' => now()->addDays(10)])->create();
        $event2 = Event::factory()->for($org)->state(['start_date' => now()->addDays(20), 'end_date' => now()->addDays(25)])->create();

        EventRegistration::factory()->for($event1)->for($attendee)->create();
        EventRegistration::factory()->for($event2)->for($attendee)->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertNotNull($data['next_event']);
        $this->assertEquals($event1->id, $data['next_event']['event']['id']);
        $this->assertArrayHasKey('days_until_start', $data['next_event']);
        $this->assertGreaterThan(0, $data['next_event']['days_until_start']);
    }

    public function test_latest_event_spotlight_is_first_past_event(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $event1 = Event::factory()->for($org)->state(['start_date' => now()->subDays(15), 'end_date' => now()->subDays(10)])->create();
        $event2 = Event::factory()->for($org)->state(['start_date' => now()->subDays(8), 'end_date' => now()->subDays(1)])->create();

        $reg1 = EventRegistration::factory()->for($event1)->for($attendee)->create();
        $reg2 = EventRegistration::factory()->for($event2)->for($attendee)->create();

        EventSession::factory()->for($event1)->create();
        EventSession::factory()->for($event1)->create();
        AttendanceRecord::factory()->for($reg1)->for(EventSession::factory()->for($event1)->create(), 'eventSession')->create();

        EventSession::factory()->for($event2)->create();
        AttendanceRecord::factory()->for($reg2)->for(EventSession::factory()->for($event2)->create(), 'eventSession')->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertNotNull($data['latest_event']);
        $this->assertEquals($event2->id, $data['latest_event']['event']['id']);
        $this->assertArrayHasKey('attendance_summary', $data['latest_event']);
    }

    public function test_stats_include_new_fields(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $upcomingEvent = Event::factory()->for($org)->state(['start_date' => now()->addDays(10), 'end_date' => now()->addDays(15)])->create();
        $pastEvent = Event::factory()->for($org)->state(['start_date' => now()->subDays(10), 'end_date' => now()->subDays(5)])->create();

        $upcomingReg = EventRegistration::factory()->for($upcomingEvent)->for($attendee)->create();
        $pastReg = EventRegistration::factory()->for($pastEvent)->for($attendee)->create();

        $session1 = EventSession::factory()->for($pastEvent)->create();
        $session2 = EventSession::factory()->for($pastEvent)->create();
        AttendanceRecord::factory()->for($pastReg)->for($session1, 'eventSession')->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertArrayHasKey('total_events_registered', $data['stats']);
        $this->assertArrayHasKey('total_upcoming_events', $data['stats']);
        $this->assertArrayHasKey('total_past_events', $data['stats']);
        $this->assertArrayHasKey('total_sessions_attended', $data['stats']);
        $this->assertArrayHasKey('total_sessions_available', $data['stats']);
        $this->assertArrayHasKey('attendance_rate', $data['stats']);

        $this->assertEquals(2, $data['stats']['total_events_registered']);
        $this->assertEquals(1, $data['stats']['total_upcoming_events']);
        $this->assertEquals(1, $data['stats']['total_past_events']);
        $this->assertEquals(1, $data['stats']['total_sessions_attended']);
        $this->assertEquals(2, $data['stats']['total_sessions_available']);
        $this->assertEquals(50, $data['stats']['attendance_rate']);
    }

    public function test_attendance_rate_is_zero_when_no_past_events(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $upcomingEvent = Event::factory()->for($org)->state(['start_date' => now()->addDays(10), 'end_date' => now()->addDays(15)])->create();
        EventRegistration::factory()->for($upcomingEvent)->for($attendee)->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/dashboard');

        $response->assertOk();
        $data = $this->extractResponseData($response->json());

        $this->assertEquals(0, $data['stats']['attendance_rate']);
    }

    public function test_unauthenticated_user_cannot_access_registrations(): void
    {
        $response = $this->getJson('/api/v1/attendee/registrations');

        $response->assertUnauthorized();
    }

    public function test_non_attendee_user_cannot_access_registrations(): void
    {
        $checker = User::factory()->checker()->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson('/api/v1/attendee/registrations');

        $response->assertForbidden();
    }

    public function test_attendee_with_no_linked_attendee_record_gets_not_found(): void
    {
        $attendeeUser = User::factory()->attendee()->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/registrations');

        $response->assertNotFound();
    }

    public function test_attendee_can_list_all_registrations(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $event1 = Event::factory()->for($org)->create();
        $event2 = Event::factory()->for($org)->create();

        EventRegistration::factory()->for($event1)->for($attendee)->create();
        EventRegistration::factory()->for($event2)->for($attendee)->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/registrations');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data);
    }

    public function test_attendee_registrations_include_event_and_attendance_data(): void
    {
        $org = Organization::factory()->create();
        $attendeeUser = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee = Attendee::factory()->for($org)->for($attendeeUser)->create();

        $event = Event::factory()->for($org)->create();
        $registration = EventRegistration::factory()
            ->for($event)
            ->for($attendee)
            ->state(['id_card_generated_at' => now()])
            ->create();

        $response = $this->actingAs($attendeeUser, 'sanctum')
            ->getJson('/api/v1/attendee/registrations');

        $response->assertOk();

        $fullResponse = $response->json();
        $data = is_array($fullResponse['data'] ?? null) ? $fullResponse['data'] : $fullResponse;

        $this->assertCount(1, $data);
        $registrationData = $data[0];

        $this->assertEquals($registration->id, $registrationData['id']);
        $this->assertEquals($event->id, $registrationData['event_id']);
        $this->assertEquals($attendee->id, $registrationData['attendee_id']);
        $this->assertTrue($registrationData['id_card_ready']);
        $this->assertNotNull($registrationData['event']);
        $this->assertEquals($event->id, $registrationData['event']['id']);
    }

    public function test_attendee_does_not_see_other_attendees_registrations_in_list(): void
    {
        $org = Organization::factory()->create();

        $attendee1User = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee1 = Attendee::factory()->for($org)->for($attendee1User)->create();

        $attendee2User = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $attendee2 = Attendee::factory()->for($org)->for($attendee2User)->create();

        $event = Event::factory()->for($org)->create();

        $registration1 = EventRegistration::factory()->for($event)->for($attendee1)->create();
        EventRegistration::factory()->for($event)->for($attendee2)->create();

        $response = $this->actingAs($attendee1User, 'sanctum')
            ->getJson('/api/v1/attendee/registrations');

        $response->assertOk();

        $fullResponse = $response->json();
        $data = is_array($fullResponse['data'] ?? null) ? $fullResponse['data'] : $fullResponse;

        $this->assertCount(1, $data);
        $this->assertEquals($registration1->id, $data[0]['id']);
    }
}
