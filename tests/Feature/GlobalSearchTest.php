<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventProgramDay;
use App\Models\EventProgramItem;
use App\Models\EventProgramSection;
use App\Models\EventSession;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_search_requires_authentication_and_a_valid_query(): void
    {
        $this->getJson('/api/v1/search?q=Convention')->assertUnauthorized();

        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/search?q=a')->assertUnprocessable();
        $this->getJson('/api/v1/search?q[]=Convention')->assertUnprocessable();
        $this->getJson('/api/v1/search?q=Convention&all_events=1&event_id=1')->assertUnprocessable();
    }

    public function test_active_event_scope_and_all_events_toggle_keep_unrelated_categories_visible(): void
    {
        $organization = Organization::factory()->create(['name' => 'Meridian Union']);
        $admin = User::factory()->orgAdmin()->for($organization)->create(['name' => 'Meridian Admin']);
        $union = Union::factory()->for($organization)->create(['name' => 'Meridian Union']);
        $mission = Mission::factory()->for($organization)->for($union)->create(['name' => 'Meridian Mission']);
        $church = Church::factory()->for($organization)->for($union)->for($mission)->create(['name' => 'Meridian Church']);
        $firstEvent = Event::factory()->for($organization)->create(['name' => 'Meridian Spring']);
        $secondEvent = Event::factory()->for($organization)->create(['name' => 'Meridian Autumn']);
        $firstAttendee = Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Meridian', 'last_name' => 'First', 'mobile_no' => '09171234567', 'email_address' => 'first@example.com', 'organization_level' => 'union', 'union_id' => $union->id, 'registration_confirmed_at' => now()]);
        $secondAttendee = Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Meridian', 'last_name' => 'Second', 'mobile_no' => '09171230000', 'email_address' => 'second@example.com', 'organization_level' => 'union', 'union_id' => $union->id, 'registration_confirmed_at' => now()]);
        $firstRegistration = $this->registerInEvent($firstEvent, $firstAttendee);
        $this->registerInEvent($secondEvent, $secondAttendee);
        $session = EventSession::factory()->for($firstEvent)->create(['name' => 'Meridian Session']);
        $attendance = AttendanceRecord::factory()->for($firstRegistration)->for($session, 'eventSession')->create();
        $program = EventProgram::factory()->for($firstEvent)->create(['title' => 'Meridian Program']);
        $day = EventProgramDay::factory()->for($program, 'program')->create(['title' => 'Meridian Day']);
        $section = EventProgramSection::factory()->for($day, 'day')->create(['title' => 'Meridian Section']);
        EventProgramItem::factory()->create(['event_program_id' => $program->id, 'event_program_section_id' => $section->id, 'part_title' => 'Meridian Part']);
        Speaker::factory()->for($firstEvent)->create(['name' => 'Meridian Speaker']);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/search?q=Meridian&event_id={$firstEvent->id}")->assertOk();

        $this->assertSame([$firstEvent->id], $this->ids($response->json(), 'events'));
        $this->assertSame([$firstAttendee->id], $this->ids($response->json(), 'attendees'));
        $this->assertSame([$session->id], $this->ids($response->json(), 'sessions'));
        $this->assertSame([$attendance->id], $this->ids($response->json(), 'attendance'));
        $this->assertSame(4, $this->group($response->json(), 'program')['total']);
        $this->assertSame(1, $this->group($response->json(), 'speakers')['total']);
        $this->assertSame([$admin->id], $this->ids($response->json(), 'users'));
        $this->assertSame([$union->id], $this->ids($response->json(), 'unions'));
        $this->assertSame([$mission->id], $this->ids($response->json(), 'missions'));
        $this->assertSame([$church->id], $this->ids($response->json(), 'churches'));
        $this->assertSame([$firstAttendee->id], $this->ids($this->getJson("/api/v1/search?q=Meridian%20First&event_id={$firstEvent->id}&category=attendees")->assertOk()->json(), 'attendees'));
        $this->assertSame([$firstAttendee->id], $this->ids($this->getJson("/api/v1/search?q=09171234567&event_id={$firstEvent->id}&category=attendees")->assertOk()->json(), 'attendees'));
        $this->assertSame([], $this->ids($this->getJson('/api/v1/search?q='.urlencode($firstRegistration->qr_token).'&category=registrations')->assertOk()->json(), 'registrations'));

        $this->getJson("/api/v1/events/{$firstEvent->id}/sessions/{$session->id}/attendance?record_id={$attendance->id}")
            ->assertOk()->assertJsonPath('data.0.id', $attendance->id);
        $this->getJson("/api/v1/events/{$secondEvent->id}/sessions/{$session->id}/attendance?record_id={$attendance->id}")
            ->assertNotFound();

        $all = $this->getJson('/api/v1/search?q=Meridian&all_events=1')->assertOk()->json();
        $this->assertEqualsCanonicalizing([$firstEvent->id, $secondEvent->id], $this->ids($all, 'events'));
        $this->assertEqualsCanonicalizing([$firstAttendee->id, $secondAttendee->id], $this->ids($all, 'attendees'));
        $this->assertSame([$union->id], $this->ids($all, 'unions'));
    }

    public function test_roles_and_organizations_cannot_see_inaccessible_records(): void
    {
        $ownOrganization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $ownEvent = Event::factory()->for($ownOrganization)->create(['name' => 'Hidden Summit Own']);
        $restrictedEvent = Event::factory()->for($ownOrganization)->create(['name' => 'Hidden Summit Restricted']);
        $restrictedSession = EventSession::factory()->for($restrictedEvent)->create();
        $otherEvent = Event::factory()->for($otherOrganization)->create(['name' => 'Hidden Summit Other']);
        $checker = User::factory()->checker()->for($ownOrganization)->create();
        $checker->accessibleEvents()->attach($ownEvent);
        $otherAttendee = Attendee::create(['organization_id' => $otherOrganization->id, 'first_name' => 'Hidden', 'last_name' => 'Person']);
        $this->registerInEvent($otherEvent, $otherAttendee);
        $restrictedAttendee = Attendee::create(['organization_id' => $ownOrganization->id, 'first_name' => 'Hidden', 'last_name' => 'Restricted']);
        $this->registerInEvent($restrictedEvent, $restrictedAttendee);
        Speaker::factory()->for($ownEvent)->create(['name' => 'Hidden Speaker']);

        $result = $this->actingAs($checker, 'sanctum')->getJson('/api/v1/search?q=Hidden&all_events=1')->assertOk()->json();
        $this->assertSame([$ownEvent->id], $this->ids($result, 'events'));
        $this->assertSame([], $this->ids($result, 'attendees'));
        $this->assertNull($this->group($result, 'users'));
        $this->assertNull($this->group($result, 'speakers'));
        $this->getJson("/api/v1/search?q=Hidden&event_id={$restrictedEvent->id}")->assertForbidden();
        $this->getJson("/api/v1/events/{$restrictedEvent->id}")->assertForbidden();
        $this->getJson("/api/v1/events/{$restrictedEvent->id}/sessions/{$restrictedSession->id}/attendance")->assertForbidden();

        $orgAdmin = User::factory()->orgAdmin()->for($ownOrganization)->create();
        $adminResult = $this->actingAs($orgAdmin, 'sanctum')->getJson('/api/v1/search?q=Hidden&all_events=1')->assertOk()->json();
        $this->assertEqualsCanonicalizing([$ownEvent->id, $restrictedEvent->id], $this->ids($adminResult, 'events'));
        $this->assertSame([$restrictedAttendee->id], $this->ids($adminResult, 'attendees'));
        $this->getJson("/api/v1/search?q=Hidden&event_id={$otherEvent->id}")->assertForbidden();
    }

    public function test_super_admin_can_search_all_organizations_and_category_pages_are_paginated(): void
    {
        $firstOrganization = Organization::factory()->create(['name' => 'Harbor District']);
        $secondOrganization = Organization::factory()->create(['name' => 'Harbor Region']);
        $firstEvent = Event::factory()->for($firstOrganization)->create(['name' => 'Harbor Gathering']);
        $secondEvent = Event::factory()->for($secondOrganization)->create(['name' => 'Harbor Congress']);
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin, 'sanctum');
        $firstPage = $this->getJson('/api/v1/search?q=Harbor&category=events&per_page=1&page=1')->assertOk()->json();
        $secondPage = $this->getJson('/api/v1/search?q=Harbor&category=events&per_page=1&page=2')->assertOk()->json();

        $this->assertSame(2, $firstPage['groups'][0]['total']);
        $this->assertTrue($firstPage['groups'][0]['has_more']);
        $this->assertSame([$firstEvent->id], $this->ids($firstPage, 'events'));
        $this->assertSame([$secondEvent->id], $this->ids($secondPage, 'events'));
        $this->assertFalse($secondPage['groups'][0]['has_more']);

        $this->getJson('/api/v1/search?q=Harbor&category=organizations')
            ->assertOk()->assertJsonPath('groups.0.total', 2);
        $this->getJson('/api/v1/search?q=Harbor&event_id=999999')->assertForbidden();
    }

    public function test_attendee_sees_only_owned_registered_event_content_and_pagination(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->attendee()->create();
        $attendee = Attendee::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'first_name' => 'Registered', 'last_name' => 'Guest']);
        $ownEvent = Event::factory()->for($organization)->create(['name' => 'Summit Own']);
        $otherEvent = Event::factory()->for($organization)->create(['name' => 'Summit Other']);
        $ownRegistration = $this->registerInEvent($ownEvent, $attendee);
        $session = EventSession::factory()->for($ownEvent)->create(['name' => 'Summit Session']);
        $ownAttendance = AttendanceRecord::factory()->for($ownRegistration)->for($session, 'eventSession')->create();
        Speaker::factory()->for($ownEvent)->create(['name' => 'Summit Speaker']);
        EventProgram::factory()->for($ownEvent)->create(['title' => 'Summit Program']);
        $otherAttendee = Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Other', 'last_name' => 'Guest']);
        $this->registerInEvent($otherEvent, $otherAttendee);
        $sameEventAttendee = Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Registered', 'last_name' => 'Other']);
        $otherRegistration = $this->registerInEvent($ownEvent, $sameEventAttendee);
        AttendanceRecord::factory()->for($otherRegistration)->for($session, 'eventSession')->create();

        $result = $this->actingAs($user, 'sanctum')->getJson('/api/v1/search?q=Summit&all_events=1')->assertOk()->json();
        $this->assertSame([$ownEvent->id], $this->ids($result, 'events'));
        $this->assertSame([$session->id], $this->ids($result, 'sessions'));
        $owned = $this->getJson('/api/v1/search?q=Registered&category=attendance')->assertOk()->json();
        $this->assertSame([$ownAttendance->id], $this->ids($owned, 'attendance'));
        $this->getJson("/api/v1/attendee/events/{$ownEvent->id}/attendance")
            ->assertOk()->assertJsonPath('sessions.0.attendance_record_id', $ownAttendance->id);
        $this->getJson("/api/v1/attendee/events/{$ownEvent->id}/program")->assertOk();
        $this->getJson('/api/v1/attendee/registrations')->assertOk()->assertJsonPath('data.0.event.id', $ownEvent->id);
        $this->assertSame([$ownRegistration->id], $this->ids($this->getJson('/api/v1/search?q=Registered&category=registrations')->assertOk()->json(), 'registrations'));
        $this->assertSame(1, $this->group($result, 'speakers')['total']);
        $this->assertNull($this->group($result, 'users'));
        $this->assertNull($this->group($result, 'attendees'));
        $this->getJson('/api/v1/search?q=Summit&category=users')->assertForbidden();
        $this->getJson("/api/v1/search?q=Summit&event_id={$otherEvent->id}")->assertForbidden();

        $this->getJson('/api/v1/search?q=Summit&category=events&per_page=1&page=1')
            ->assertOk()->assertJsonPath('groups.0.total', 1);
    }

    public function test_breakout_check_in_matched_by_breakout_title_carries_breakout_reference(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $breakout = Breakout::factory()->for($event)->create(['title' => 'Zephyr Workshop']);
        $attendee = $this->registerInEvent($event, Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Ann', 'last_name' => 'Pickford']));
        $record = AttendanceRecord::factory()->for($attendee)->create(['event_session_id' => null, 'breakout_id' => $breakout->id]);
        $admin = User::factory()->orgAdmin()->for($organization)->create();

        $result = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/search?q=Zephyr&all_events=1')->assertOk()->json();

        $item = $this->group($result, 'attendance')['items'][0];
        $this->assertSame($record->id, $item['id']);
        $this->assertSame('Zephyr Workshop', $item['subtitle']);
        $this->assertSame($breakout->id, $item['breakout_id']);
        $this->assertNull($item['session_id']);
        $this->assertFalse($item['is_walk_in']);
    }

    public function test_breakout_check_in_matched_by_attendee_name_and_walk_in_flag(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $breakout = Breakout::factory()->for($event)->create(['title' => 'Lantern Talk']);
        $attendee = $this->registerInEvent($event, Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Walker', 'last_name' => 'Quill']));
        AttendanceRecord::factory()->for($attendee)->create(['event_session_id' => null, 'breakout_id' => $breakout->id, 'is_walk_in' => true]);
        $admin = User::factory()->orgAdmin()->for($organization)->create();

        $item = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/search?q=Quill&category=attendance')->assertOk()->json('groups.0.items.0');

        $this->assertSame('Lantern Talk', $item['subtitle']);
        $this->assertSame($breakout->id, $item['breakout_id']);
        $this->assertTrue($item['is_walk_in']);
    }

    public function test_session_check_in_keeps_shape_with_null_breakout_and_no_walk_in(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $session = EventSession::factory()->for($event)->create(['name' => 'Opening Plenary']);
        $attendee = $this->registerInEvent($event, Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Sam', 'last_name' => 'Doe']));
        AttendanceRecord::factory()->for($attendee)->for($session, 'eventSession')->create();
        $admin = User::factory()->orgAdmin()->for($organization)->create();

        $item = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/search?q=Sam&category=attendance')->assertOk()->json('groups.0.items.0');

        $this->assertSame('Opening Plenary', $item['subtitle']);
        $this->assertSame($session->id, $item['session_id']);
        $this->assertNull($item['breakout_id']);
        $this->assertFalse($item['is_walk_in']);
    }

    public function test_attendee_and_checker_see_only_their_own_scope_of_breakout_check_ins(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $restricted = Event::factory()->for($organization)->create();
        $breakout = Breakout::factory()->for($event)->create(['title' => 'Cedar Forum']);
        $restrictedBreakout = Breakout::factory()->for($restricted)->create(['title' => 'Cedar Hidden']);
        $user = User::factory()->attendee()->create();
        $mine = $this->registerInEvent($event, Attendee::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'first_name' => 'Mine', 'last_name' => 'Own']));
        $other = $this->registerInEvent($event, Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Theirs', 'last_name' => 'Else']));
        $far = $this->registerInEvent($restricted, Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Far', 'last_name' => 'Away']));
        $own = AttendanceRecord::factory()->for($mine)->create(['event_session_id' => null, 'breakout_id' => $breakout->id]);
        $foreign = AttendanceRecord::factory()->for($other)->create(['event_session_id' => null, 'breakout_id' => $breakout->id]);
        $hidden = AttendanceRecord::factory()->for($far)->create(['event_session_id' => null, 'breakout_id' => $restrictedBreakout->id]);

        $this->actingAs($user, 'sanctum');
        $this->assertSame([$own->id], $this->ids($this->getJson('/api/v1/search?q=Cedar&category=attendance')->assertOk()->json(), 'attendance'));

        $checker = User::factory()->checker()->for($organization)->create();
        $checker->accessibleEvents()->attach($event);
        $this->actingAs($checker, 'sanctum');
        $this->assertEqualsCanonicalizing([$own->id, $foreign->id], $this->ids($this->getJson('/api/v1/search?q=Cedar&all_events=1')->assertOk()->json(), 'attendance'));
        $this->assertNotContains($hidden->id, $this->ids($this->getJson('/api/v1/search?q=Cedar&all_events=1')->json(), 'attendance'));
    }

    private function group(array $response, string $key): ?array
    {
        foreach ($response['groups'] as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }

        return null;
    }

    private function ids(array $response, string $key): array
    {
        return array_column($this->group($response, $key)['items'] ?? [], 'id');
    }
}
