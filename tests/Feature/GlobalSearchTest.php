<?php

namespace Tests\Feature;

use App\Enums\FormFieldType;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Certificate;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventProgramDay;
use App\Models\EventProgramItem;
use App\Models\EventProgramSection;
use App\Models\EventSession;
use App\Models\Form;
use App\Models\IssuedCertificate;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_breakouts_match_title_description_room_topic_and_speaker_name(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Harvest Summit', 'pick_mode' => null]);
        $byTitle = Breakout::factory()->for($event)->create(['title' => 'Quillon Basics', 'date' => '2026-11-05', 'start_time' => '09:00', 'end_time' => '10:30', 'room' => 'Hall A']);
        $byDescription = Breakout::factory()->for($event)->create(['description' => 'About quillon care']);
        $byRoom = Breakout::factory()->for($event)->create(['room' => 'Quillon Annex']);
        $byTopic = Breakout::factory()->for($event)->create(['topics' => ['Quillon forging']]);
        $bySpeaker = Breakout::factory()->for($event)->create();
        $bySpeaker->speakers()->attach(Speaker::factory()->for($event)->create(['name' => 'Quillon Smith']));
        Breakout::factory()->for($event)->create(['title' => 'Unrelated', 'description' => null, 'room' => 'Room 1', 'topics' => ['other']]);
        $admin = User::factory()->orgAdmin()->for($organization)->create();

        $result = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/search?q=Quillon&all_events=1')->assertOk()->json();

        $this->assertEqualsCanonicalizing(
            [$byTitle->id, $byDescription->id, $byRoom->id, $byTopic->id, $bySpeaker->id],
            $this->ids($result, 'breakouts'),
        );
        $item = collect($this->group($result, 'breakouts')['items'])->firstWhere('id', $byTitle->id);
        $this->assertSame('breakout', $item['type']);
        $this->assertSame('Quillon Basics', $item['title']);
        $this->assertSame('2026-11-05 09:00-10:30 · Hall A', $item['subtitle']);
        $this->assertSame('Harvest Summit', $item['context']);
        $this->assertSame($event->id, $item['event_id']);
    }

    public function test_breakouts_group_follows_sessions_and_accepts_category(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        Breakout::factory()->for($event)->create(['title' => 'Order Check']);
        $admin = User::factory()->orgAdmin()->for($organization)->create();

        $keys = array_column($this->actingAs($admin, 'sanctum')->getJson('/api/v1/search?q=Order&all_events=1')->assertOk()->json('groups'), 'key');
        $this->assertSame(array_search('sessions', $keys) + 1, array_search('breakouts', $keys));
        $this->getJson('/api/v1/search?q=Order&category=breakouts')->assertOk()->assertJsonPath('groups.0.key', 'breakouts');
    }

    public function test_breakouts_are_isolated_by_organization_and_checker_access_and_hidden_from_attendees(): void
    {
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $ownEvent = Event::factory()->for($own)->create();
        $restrictedEvent = Event::factory()->for($own)->create();
        $otherEvent = Event::factory()->for($other)->create();
        $ownBreakout = Breakout::factory()->for($ownEvent)->create(['title' => 'Lantern Own']);
        $restricted = Breakout::factory()->for($restrictedEvent)->create(['title' => 'Lantern Restricted']);
        $foreign = Breakout::factory()->for($otherEvent)->create(['title' => 'Lantern Foreign']);

        $this->actingAs(User::factory()->orgAdmin()->for($own)->create(), 'sanctum');
        $this->assertEqualsCanonicalizing([$ownBreakout->id, $restricted->id], $this->ids($this->getJson('/api/v1/search?q=Lantern&all_events=1')->json(), 'breakouts'));

        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum');
        $this->assertCount(3, $this->ids($this->getJson('/api/v1/search?q=Lantern&all_events=1')->json(), 'breakouts'));

        $checker = User::factory()->checker()->for($own)->create();
        $checker->accessibleEvents()->attach($ownEvent);
        $this->actingAs($checker, 'sanctum');
        $this->assertSame([$ownBreakout->id], $this->ids($this->getJson('/api/v1/search?q=Lantern&all_events=1')->json(), 'breakouts'));
    }

    public function test_attendee_sees_breakouts_of_joined_events_with_picked_flag_but_not_of_disabled_or_other_events(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->attendee()->create();
        $joined = Event::factory()->for($organization)->create(['name' => 'Joined Summit', 'pick_mode' => 'required', 'pick_min' => 1, 'pick_max' => 2]);
        $disabled = Event::factory()->for($organization)->create(['pick_mode' => null]);
        $other = Event::factory()->for($organization)->create(['pick_mode' => 'required', 'pick_min' => 1, 'pick_max' => 2]);
        $mine = $this->registerInEvent($joined, Attendee::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'first_name' => 'Pat', 'last_name' => 'Picker']));
        $this->registerInEvent($disabled, Attendee::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'first_name' => 'Pat', 'last_name' => 'Picker']));
        $picked = Breakout::factory()->for($joined)->create(['title' => 'Mosaic Picked']);
        $unpicked = Breakout::factory()->for($joined)->create(['title' => 'Mosaic Unpicked']);
        $offered = Breakout::factory()->for($disabled)->create(['title' => 'Mosaic Disabled']);
        $elsewhere = Breakout::factory()->for($other)->create(['title' => 'Mosaic Elsewhere']);
        $picked->picks()->attach($mine);
        $someoneElse = $this->registerInEvent($joined, Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Other', 'last_name' => 'Person']));
        $unpicked->picks()->attach($someoneElse);
        $this->actingAs($user, 'sanctum');

        $result = $this->getJson('/api/v1/search?q=Mosaic&all_events=1')->assertOk()->json();

        $this->assertEqualsCanonicalizing([$picked->id, $unpicked->id], $this->ids($result, 'breakouts'));
        $items = collect($this->group($result, 'breakouts')['items']);
        $this->assertTrue($items->firstWhere('id', $picked->id)['picked']);
        $this->assertFalse($items->firstWhere('id', $unpicked->id)['picked']);
        $this->assertSame('Joined Summit', $items->firstWhere('id', $picked->id)['context']);
        $this->getJson('/api/v1/search?q=Mosaic&category=breakouts')->assertOk()->assertJsonPath('groups.0.key', 'breakouts');
    }

    public function test_staff_breakout_items_do_not_carry_picked_flag(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        Breakout::factory()->for($event)->create(['title' => 'Staffview']);

        $result = $this->actingAs(User::factory()->orgAdmin()->for($organization)->create(), 'sanctum')
            ->getJson('/api/v1/search?q=Staffview&all_events=1')->json();
        $item = $this->group($result, 'breakouts')['items'][0];

        $this->assertArrayNotHasKey('picked', $item);
    }

    public function test_breakouts_category_is_paginated(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        Breakout::factory()->count(7)->for($event)->create(['title' => 'Paged Breakout']);
        $this->actingAs(User::factory()->orgAdmin()->for($organization)->create(), 'sanctum');

        $preview = $this->group($this->getJson('/api/v1/search?q=Paged&all_events=1')->json(), 'breakouts');
        $this->assertCount(5, $preview['items']);
        $this->assertSame(7, $preview['total']);
        $second = $this->group($this->getJson('/api/v1/search?q=Paged&category=breakouts&page=2&per_page=5')->json(), 'breakouts');
        $this->assertCount(2, $second['items']);
        $this->assertFalse($second['has_more']);
    }

    public function test_forms_match_title_description_and_active_field_label_with_subtitles(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Harvest Summit']);
        $byTitle = Form::factory()->for($event)->required()->published()->create(['title' => 'Zephyr Waiver']);
        $byDescription = Form::factory()->for($event)->create(['title' => 'Plain', 'description' => 'Collects zephyr data']);
        $byField = Form::factory()->for($event)->create(['title' => 'Merch']);
        $byField->fields()->create(['type' => FormFieldType::ShortText, 'label' => 'Zephyr size']);
        $archived = Form::factory()->for($event)->create(['title' => 'Old']);
        $archived->fields()->create(['type' => FormFieldType::ShortText, 'label' => 'Zephyr gone', 'archived_at' => now()]);
        $admin = User::factory()->orgAdmin()->for($organization)->create();

        $result = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/search?q=Zephyr&all_events=1')->assertOk()->json();

        $this->assertEqualsCanonicalizing([$byTitle->id, $byDescription->id, $byField->id], $this->ids($result, 'forms'));
        $items = collect($this->group($result, 'forms')['items']);
        $item = $items->firstWhere('id', $byTitle->id);
        $this->assertSame('form', $item['type']);
        $this->assertSame('Zephyr Waiver', $item['title']);
        $this->assertSame('Required · Published', $item['subtitle']);
        $this->assertSame('Harvest Summit', $item['context']);
        $this->assertSame($event->id, $item['event_id']);
        $this->assertSame('Optional · Draft', $items->firstWhere('id', $byDescription->id)['subtitle']);
        $this->assertSame('Field: Zephyr size', $items->firstWhere('id', $byField->id)['subtitle']);
    }

    public function test_form_answers_never_match(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $form = Form::factory()->for($event)->create(['title' => 'Survey']);
        $field = $form->fields()->create(['type' => FormFieldType::ShortText, 'label' => 'Notes']);
        $attendee = Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Ann', 'last_name' => 'Lee']);
        $submission = $form->submissions()->create(['attendee_id' => $attendee->id]);
        $submission->answers()->create(['form_field_id' => $field->id, 'value' => 'Secretanswer']);

        $result = $this->actingAs(User::factory()->orgAdmin()->for($organization)->create(), 'sanctum')
            ->getJson('/api/v1/search?q=Secretanswer&all_events=1')->assertOk()->json();

        $this->assertSame([], $this->ids($result, 'forms'));
    }

    public function test_forms_group_follows_breakouts_and_is_admin_only(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        Form::factory()->for($event)->create(['title' => 'Gateform']);

        $keys = array_column($this->actingAs(User::factory()->orgAdmin()->for($organization)->create(), 'sanctum')->getJson('/api/v1/search?q=Gateform&all_events=1')->json('groups'), 'key');
        $this->assertSame(array_search('breakouts', $keys) + 1, array_search('forms', $keys));
        $this->getJson('/api/v1/search?q=Gateform&category=forms')->assertOk()->assertJsonPath('groups.0.key', 'forms');
        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum')->getJson('/api/v1/search?q=Gateform&category=forms')->assertOk();

        $checker = User::factory()->checker()->for($organization)->create();
        $attendee = User::factory()->attendee()->create();
        foreach ([$checker, $attendee] as $user) {
            $this->actingAs($user, 'sanctum');
            $keys = array_column($this->getJson('/api/v1/search?q=Gateform&all_events=1')->assertOk()->json('groups'), 'key');
            $this->assertNotContains('forms', $keys);
            $this->getJson('/api/v1/search?q=Gateform&category=forms')->assertForbidden();
        }
    }

    public function test_forms_are_isolated_by_organization_and_category_is_paginated(): void
    {
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $ownEvent = Event::factory()->for($own)->create();
        Form::factory()->count(7)->for($ownEvent)->create(['title' => 'Pagedform']);
        Form::factory()->for(Event::factory()->for($other)->create())->create(['title' => 'Pagedform Foreign']);

        $this->actingAs(User::factory()->orgAdmin()->for($own)->create(), 'sanctum');
        $preview = $this->group($this->getJson('/api/v1/search?q=Pagedform&all_events=1')->json(), 'forms');
        $this->assertCount(5, $preview['items']);
        $this->assertSame(7, $preview['total']);
        $second = $this->group($this->getJson('/api/v1/search?q=Pagedform&category=forms&page=2&per_page=5')->json(), 'forms');
        $this->assertCount(2, $second['items']);

        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum');
        $this->assertSame(8, $this->group($this->getJson('/api/v1/search?q=Pagedform&all_events=1')->json(), 'forms')['total']);
    }

    public function test_certificates_match_title_only_including_drafts_with_status_subtitles(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Harvest Summit']);
        $draft = Certificate::factory()->for($event)->create(['title' => 'Quasar Participation']);
        $published = Certificate::factory()->for($event)->create(['title' => 'Quasar Attendance', 'status' => 'published', 'published_at' => now(), 'release_at' => '2027-03-05 14:30:00']);
        Certificate::factory()->for($event)->create(['title' => 'Other']);
        $admin = User::factory()->orgAdmin()->for($organization)->create();

        $result = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/search?q=Quasar&all_events=1')->assertOk()->json();

        $this->assertEqualsCanonicalizing([$draft->id, $published->id], $this->ids($result, 'certificates'));
        $items = collect($this->group($result, 'certificates')['items']);
        $item = $items->firstWhere('id', $draft->id);
        $this->assertSame('certificate', $item['type']);
        $this->assertSame('Quasar Participation', $item['title']);
        $this->assertSame('Draft', $item['subtitle']);
        $this->assertSame('Harvest Summit', $item['context']);
        $this->assertSame($event->id, $item['event_id']);
        $this->assertSame('Published · releases 2027-03-05 14:30', $items->firstWhere('id', $published->id)['subtitle']);
    }

    public function test_certificates_never_return_issued_certificates_to_admins(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $certificate = Certificate::factory()->for($event)->create(['title' => 'Nebula Award']);
        $attendee = Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Zorbaname', 'last_name' => 'Lee']);
        $certificate->issuedCertificates()->create(['attendee_id' => $attendee->id, 'organization_id' => $organization->id]);

        $result = $this->actingAs(User::factory()->orgAdmin()->for($organization)->create(), 'sanctum')
            ->getJson('/api/v1/search?q=Zorbaname&all_events=1')->assertOk()->json();

        $this->assertSame([], $this->ids($result, 'certificates'));
    }

    public function test_certificates_group_follows_forms_and_is_hidden_from_checkers(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        Certificate::factory()->for($event)->create(['title' => 'Gatecert']);

        $keys = array_column($this->actingAs(User::factory()->orgAdmin()->for($organization)->create(), 'sanctum')->getJson('/api/v1/search?q=Gatecert&all_events=1')->json('groups'), 'key');
        $this->assertSame(array_search('forms', $keys) + 1, array_search('certificates', $keys));
        $this->getJson('/api/v1/search?q=Gatecert&category=certificates')->assertOk()->assertJsonPath('groups.0.key', 'certificates');
        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum')->getJson('/api/v1/search?q=Gatecert&category=certificates')->assertOk();

        $this->actingAs(User::factory()->checker()->for($organization)->create(), 'sanctum');
        $keys = array_column($this->getJson('/api/v1/search?q=Gatecert&all_events=1')->assertOk()->json('groups'), 'key');
        $this->assertNotContains('certificates', $keys);
        $this->getJson('/api/v1/search?q=Gatecert&category=certificates')->assertForbidden();
    }

    public function test_certificates_are_isolated_by_organization_and_category_is_paginated(): void
    {
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        Certificate::factory()->count(7)->for(Event::factory()->for($own)->create())->create(['title' => 'Pagedcert']);
        Certificate::factory()->for(Event::factory()->for($other)->create())->create(['title' => 'Pagedcert Foreign']);

        $this->actingAs(User::factory()->orgAdmin()->for($own)->create(), 'sanctum');
        $preview = $this->group($this->getJson('/api/v1/search?q=Pagedcert&all_events=1')->json(), 'certificates');
        $this->assertCount(5, $preview['items']);
        $this->assertSame(7, $preview['total']);
        $second = $this->group($this->getJson('/api/v1/search?q=Pagedcert&category=certificates&page=2&per_page=5')->json(), 'certificates');
        $this->assertCount(2, $second['items']);

        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum');
        $this->assertSame(8, $this->group($this->getJson('/api/v1/search?q=Pagedcert&all_events=1')->json(), 'certificates')['total']);
    }

    public function test_attendee_finds_only_issued_certificates_visible_on_my_certificates(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $user = User::factory()->attendee()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Harvest Summit']);
        $mine = $this->joinedAttendee($event, $user);
        $released = ['status' => 'published', 'published_at' => '2026-01-01 00:00:00', 'release_at' => '2026-01-02 00:00:00', 'release_rule' => 'everyone'];
        $visible = Certificate::factory()->for($event)->create(['title' => 'Zenith Visible', ...$released]);
        $draft = Certificate::factory()->for($event)->create(['title' => 'Zenith Draft']);
        $future = Certificate::factory()->for($event)->create(['title' => 'Zenith Future', ...$released, 'release_at' => now()->addYear()]);
        $noFile = Certificate::factory()->for($event)->create(['title' => 'Zenith Nofile', ...$released]);
        $ineligible = Certificate::factory()->for($event)->create(['title' => 'Zenith Rate', ...$released, 'release_rule' => 'rate_75']);
        $other = Certificate::factory()->for($event)->create(['title' => 'Unrelated', ...$released]);
        $issued = [];
        foreach ([$visible, $draft, $future, $ineligible, $other] as $certificate) {
            Storage::disk('local')->put($path = "certificates/{$certificate->id}/{$mine->id}.pdf", 'PDF');
            $issued[$certificate->title] = IssuedCertificate::factory()->generated($path)->create(['certificate_id' => $certificate->id, 'attendee_id' => $mine->id]);
        }
        IssuedCertificate::factory()->create(['certificate_id' => $noFile->id, 'attendee_id' => $mine->id]);

        $result = $this->actingAs($user, 'sanctum')->getJson('/api/v1/search?q=Zenith&all_events=1')->assertOk()->json();

        $this->assertSame([$issued['Zenith Visible']->id], $this->ids($result, 'certificates'));
        $this->assertSame([
            'type' => 'issued_certificate', 'id' => $issued['Zenith Visible']->id, 'title' => 'Zenith Visible', 'subtitle' => null,
            'context' => 'Harvest Summit', 'event_id' => $event->id, 'certificate_id' => $visible->id,
        ], $this->group($result, 'certificates')['items'][0]);
        $this->getJson("/api/v1/attendee/events/{$event->id}/certificates")->assertOk()->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_attendee_issued_certificates_span_joined_events_and_are_paginated(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $user = User::factory()->attendee()->create();
        $released = ['status' => 'published', 'published_at' => '2026-01-01 00:00:00', 'release_at' => '2026-01-02 00:00:00', 'release_rule' => 'everyone'];
        foreach ([1, 2] as $n) {
            $event = Event::factory()->for($organization)->create();
            $attendee = $this->joinedAttendee($event, $user);
            foreach (range(1, 3) as $i) {
                $certificate = Certificate::factory()->for($event)->create(['title' => "Pagedissue {$n}-{$i}", ...$released]);
                Storage::disk('local')->put($path = "certificates/{$certificate->id}/{$attendee->id}.pdf", 'PDF');
                IssuedCertificate::factory()->generated($path)->create(['certificate_id' => $certificate->id, 'attendee_id' => $attendee->id]);
            }
        }
        $foreign = Event::factory()->for($organization)->create();
        $stranger = $this->joinedAttendee($foreign, null);
        $certificate = Certificate::factory()->for($foreign)->create(['title' => 'Pagedissue foreign', ...$released]);
        Storage::disk('local')->put($path = "certificates/{$certificate->id}/{$stranger->id}.pdf", 'PDF');
        IssuedCertificate::factory()->generated($path)->create(['certificate_id' => $certificate->id, 'attendee_id' => $stranger->id]);

        $this->actingAs($user, 'sanctum');
        $preview = $this->group($this->getJson('/api/v1/search?q=Pagedissue&all_events=1')->json(), 'certificates');
        $this->assertSame(6, $preview['total']);
        $this->assertCount(5, $preview['items']);
        $this->assertTrue($preview['has_more']);
        $second = $this->group($this->getJson('/api/v1/search?q=Pagedissue&category=certificates&page=2&per_page=4')->json(), 'certificates');
        $this->assertCount(2, $second['items']);
        $this->assertFalse($second['has_more']);
    }

    public function test_breakout_check_in_never_reports_a_session_id(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $session = EventSession::factory()->for($event)->create();
        $breakout = Breakout::factory()->for($event)->create(['title' => 'Cascade Workshop']);
        $attendee = $this->registerInEvent($event, Attendee::create(['organization_id' => $organization->id, 'first_name' => 'Ann', 'last_name' => 'Pickford']));
        AttendanceRecord::factory()->for($attendee)->create(['event_session_id' => $session->id, 'breakout_id' => $breakout->id]);
        $admin = User::factory()->orgAdmin()->for($organization)->create();

        $item = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/search?q=Cascade&category=attendance')->assertOk()->json('groups.0.items.0');

        $this->assertNull($item['session_id']);
        $this->assertSame($breakout->id, $item['breakout_id']);
    }

    public function test_picked_flag_ignores_merged_attendees(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->attendee()->create();
        $event = Event::factory()->for($organization)->create(['pick_mode' => 'required', 'pick_min' => 1, 'pick_max' => 2]);
        $live = $this->registerInEvent($event, Attendee::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'first_name' => 'Pat', 'last_name' => 'Live']));
        $otherEvent = Event::factory()->for($organization)->create(['pick_mode' => 'required', 'pick_min' => 1, 'pick_max' => 2]);
        $merged = $this->registerInEvent($otherEvent, Attendee::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'first_name' => 'Pat', 'last_name' => 'Merged']));
        $merged->forceFill(['merged_at' => now()])->saveQuietly();
        $liveBreakout = Breakout::factory()->for($event)->create(['title' => 'Mergetest Live']);
        $mergedBreakout = Breakout::factory()->for($otherEvent)->create(['title' => 'Mergetest Merged']);
        $liveBreakout->picks()->attach($live);
        $mergedBreakout->picks()->attach($merged);

        $items = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/search?q=Mergetest&category=breakouts')->assertOk()->json('groups.0.items'));

        $this->assertTrue($items->firstWhere('id', $liveBreakout->id)['picked']);
        $this->assertFalse($items->firstWhere('id', $mergedBreakout->id)['picked']);
    }

    public function test_breakout_topics_match_non_ascii_text_and_ignore_json_punctuation(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $accented = Breakout::factory()->for($event)->create(['title' => 'A', 'description' => null, 'room' => '', 'topics' => ['Café culture', 'Fé']]);
        $slashed = Breakout::factory()->for($event)->create(['title' => 'B', 'description' => null, 'room' => '', 'topics' => ['Faith/Hope']]);
        Breakout::factory()->for($event)->create(['title' => 'C', 'description' => null, 'room' => '', 'topics' => ['plain', 'other']]);
        $admin = User::factory()->orgAdmin()->for($organization)->create();
        $this->actingAs($admin, 'sanctum');

        $this->assertSame([$accented->id], $this->ids($this->getJson('/api/v1/search?q='.urlencode('Café').'&category=breakouts')->json(), 'breakouts'));
        $this->assertSame([$slashed->id], $this->ids($this->getJson('/api/v1/search?q='.urlencode('Faith/Hope').'&category=breakouts')->json(), 'breakouts'));
        $this->assertSame([], $this->ids($this->getJson('/api/v1/search?q='.urlencode('","').'&category=breakouts')->json(), 'breakouts'));
        $this->assertSame([], $this->ids($this->getJson('/api/v1/search?q='.urlencode('["pl').'&category=breakouts')->json(), 'breakouts'));
    }

    public function test_form_subtitle_for_terms_with_wildcards_reflects_where_it_matched(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $byTitle = Form::factory()->for($event)->required()->published()->create(['title' => '100% Waiver']);
        $byField = Form::factory()->for($event)->create(['title' => 'Merch']);
        $byField->fields()->create(['type' => FormFieldType::ShortText, 'label' => '100% cotton']);
        $admin = User::factory()->orgAdmin()->for($organization)->create();

        $items = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/search?q='.urlencode('100%').'&category=forms')->assertOk()->json('groups.0.items'));

        $this->assertSame('Required · Published', $items->firstWhere('id', $byTitle->id)['subtitle']);
        $this->assertSame('Field: 100% cotton', $items->firstWhere('id', $byField->id)['subtitle']);
    }

    public function test_form_search_runs_a_constant_number_of_queries(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        foreach (range(1, 4) as $n) {
            Form::factory()->for($event)->create(['title' => "Form {$n}"])->fields()->create(['type' => FormFieldType::ShortText, 'label' => "Qcount field {$n}"]);
        }
        $admin = User::factory()->orgAdmin()->for($organization)->create();
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/v1/search?q=none&category=forms');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/search?q=Qcount&category=forms')->assertOk();
        $four = count(DB::getQueryLog());
        Form::query()->first()->delete();
        DB::flushQueryLog();
        $this->getJson('/api/v1/search?q=Qcount&category=forms')->assertOk();

        $this->assertSame($four, count(DB::getQueryLog()));
    }

    public function test_attendee_certificate_search_adds_at_most_one_query_per_event_certificate(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $user = User::factory()->attendee()->create();
        $released = ['status' => 'published', 'published_at' => '2026-01-01 00:00:00', 'release_at' => '2026-01-02 00:00:00', 'release_rule' => 'everyone'];
        $join = function () use ($organization, $user, $released) {
            $event = Event::factory()->for($organization)->create();
            $attendee = $this->joinedAttendee($event, $user);
            $certificate = Certificate::factory()->for($event)->create(['title' => 'Qcount cert', ...$released]);
            Storage::disk('local')->put($path = "certificates/{$certificate->id}/{$attendee->id}.pdf", 'PDF');
            IssuedCertificate::factory()->generated($path)->create(['certificate_id' => $certificate->id, 'attendee_id' => $attendee->id]);
        };
        $join();
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/v1/search?q=Qcount&category=certificates');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/search?q=Qcount&category=certificates')->assertOk();
        $one = count(DB::getQueryLog());
        foreach (range(1, 3) as $ignored) {
            $join();
        }
        DB::flushQueryLog();
        $response = $this->getJson('/api/v1/search?q=Qcount&category=certificates')->assertOk();

        $this->assertSame(4, $response->json('groups.0.total'));
        $this->assertLessThanOrEqual($one + 3, count(DB::getQueryLog()));
    }

    private function joinedAttendee(Event $event, ?User $user): Attendee
    {
        $attendee = Attendee::factory()->for($event)->create(['user_id' => $user?->id]);
        $attendee->forceFill(['first_registered_at' => '2026-01-01 08:00:00'])->saveQuietly();

        return $attendee;
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
