<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventProgramDay;
use App\Models\EventProgramItem;
use App\Models\EventProgramSection;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendeeBreakoutsTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{0: User, 1: Attendee, 2: Event} */
    private function setupAttendee(?string $pickMode = 'multiple', bool $programPublic = false, bool $completed = true): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->attendee()->create(['organization_id' => $organization->id]);
        $attendee = Attendee::factory()->for($organization)->for($user)->create();
        $event = Event::factory()->for($organization)->create([
            'pick_mode' => $pickMode, 'pick_min' => $pickMode ? 1 : null, 'pick_max' => null,
        ]);
        $attendee = $this->registerInEvent($event, $attendee);
        $attendee->forceFill(['registration_completed_at' => $completed ? now() : null])->saveQuietly();
        EventProgram::factory()->for($event)->create(['is_public' => $programPublic]);

        return [$user, $attendee, $event];
    }

    private function getBreakouts(User $user, Event $event)
    {
        return $this->actingAs($user, 'sanctum')->getJson("/api/v1/attendee/events/{$event->id}/breakouts");
    }

    public function test_own_picks_are_listed_with_details_speakers_topics_and_program(): void
    {
        [$user, $attendee, $event] = $this->setupAttendee();
        $breakout = Breakout::factory()->for($event)->create([
            'title' => 'Youth Track', 'description' => 'About youth.', 'date' => '2030-06-25',
            'start_time' => '09:00', 'end_time' => '10:30', 'room' => 'A1', 'topics' => ['Faith', 'Service'], 'capacity' => 5,
        ]);
        $first = Speaker::factory()->for($event)->create(['name' => 'First Speaker']);
        $second = Speaker::factory()->for($event)->create(['name' => 'Second Speaker']);
        $breakout->speakers()->attach([$second->id => ['sort_order' => 2], $first->id => ['sort_order' => 1]]);
        $program = EventProgram::factory()->for($event)->create(['breakout_id' => $breakout->id, 'title' => 'Youth Program']);
        $day = EventProgramDay::factory()->create(['event_program_id' => $program->id, 'title' => 'Day']);
        $section = EventProgramSection::factory()->create(['event_program_day_id' => $day->id, 'title' => 'Opening']);
        EventProgramItem::factory()->create(['event_program_id' => $program->id, 'event_program_section_id' => $section->id, 'part_title' => 'Welcome']);
        $attendee->breakouts()->attach($breakout->id);

        $response = $this->getBreakouts($user, $event)->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $breakout->id)
            ->assertJsonPath('data.0.title', 'Youth Track')
            ->assertJsonPath('data.0.description', 'About youth.')
            ->assertJsonPath('data.0.date', '2030-06-25')
            ->assertJsonPath('data.0.start_time', '09:00')
            ->assertJsonPath('data.0.end_time', '10:30')
            ->assertJsonPath('data.0.room', 'A1')
            ->assertJsonPath('data.0.topics', ['Faith', 'Service'])
            ->assertJsonPath('data.0.speakers.0.name', 'First Speaker')
            ->assertJsonPath('data.0.speakers.1.name', 'Second Speaker')
            ->assertJsonPath('data.0.picked', true)
            ->assertJsonPath('data.0.program.title', 'Youth Program')
            ->assertJsonPath('data.0.program.days.0.sections.0.title', 'Opening')
            ->assertJsonPath('data.0.program.days.0.sections.0.items.0.part_title', 'Welcome');
    }

    public function test_breakout_without_program_has_null_program(): void
    {
        [$user, $attendee, $event] = $this->setupAttendee();
        $attendee->breakouts()->attach(Breakout::factory()->for($event)->create()->id);

        $this->getBreakouts($user, $event)->assertOk()->assertJsonPath('data.0.program', null);
    }

    public function test_unpicked_breakouts_are_hidden_when_program_is_private(): void
    {
        [$user, $attendee, $event] = $this->setupAttendee(programPublic: false);
        $mine = Breakout::factory()->for($event)->create();
        Breakout::factory()->for($event)->create();
        $attendee->breakouts()->attach($mine->id);

        $this->getBreakouts($user, $event)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_payload_includes_attendance_enabled(): void
    {
        [$user, $attendee, $event] = $this->setupAttendee();
        $on = Breakout::factory()->for($event)->create(['start_time' => '09:00', 'end_time' => '09:30']);
        $off = Breakout::factory()->for($event)->create(['start_time' => '10:00', 'end_time' => '11:00', 'attendance_enabled' => false]);
        $attendee->breakouts()->attach([$on->id, $off->id]);

        $this->getBreakouts($user, $event)->assertOk()
            ->assertJsonPath('data.0.attendance_enabled', true)
            ->assertJsonPath('data.1.attendance_enabled', false);
    }

    public function test_unpicked_breakouts_are_shown_when_program_is_public(): void
    {
        [$user, $attendee, $event] = $this->setupAttendee(programPublic: true);
        $mine = Breakout::factory()->for($event)->create(['start_time' => '10:00', 'end_time' => '11:00']);
        $other = Breakout::factory()->for($event)->create(['start_time' => '09:00', 'end_time' => '09:30']);
        $attendee->breakouts()->attach($mine->id);

        $this->getBreakouts($user, $event)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $other->id)
            ->assertJsonPath('data.0.picked', false)
            ->assertJsonPath('data.0.status', null)
            ->assertJsonPath('data.0.check_in_at', null)
            ->assertJsonPath('data.1.id', $mine->id)
            ->assertJsonPath('data.1.picked', true);
    }

    public function test_other_attendees_picks_do_not_leak(): void
    {
        [$user, , $event] = $this->setupAttendee();
        $breakout = Breakout::factory()->for($event)->create();
        Attendee::factory()->for($event->organization)->create(['event_id' => $event->id])->breakouts()->attach($breakout->id);

        $this->getBreakouts($user, $event)->assertOk()->assertJsonPath('data', []);
    }

    public function test_walk_in_breakout_is_included_with_walk_in_status_even_when_program_is_private(): void
    {
        Carbon::setTestNow('2026-06-25 12:00:00');
        [$user, $attendee, $event] = $this->setupAttendee(programPublic: false);
        $walkIn = Breakout::factory()->for($event)->create(['date' => '2026-06-25']);
        AttendanceRecord::factory()->create([
            'attendee_id' => $attendee->id, 'event_session_id' => null, 'breakout_id' => $walkIn->id,
            'check_in_at' => '2026-06-25 09:05:00', 'is_walk_in' => true,
        ]);

        $this->getBreakouts($user, $event)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $walkIn->id)
            ->assertJsonPath('data.0.picked', false)
            ->assertJsonPath('data.0.status', 'walk_in')
            ->assertJsonPath('data.0.check_in_at', fn ($value) => $value !== null);
    }

    public function test_each_attendance_status_is_reported_for_picked_breakouts(): void
    {
        Carbon::setTestNow('2026-06-25 12:00:00');
        [$user, $attendee, $event] = $this->setupAttendee();
        $make = fn (string $start, string $end) => Breakout::factory()->for($event)->create(['date' => '2026-06-25', 'start_time' => $start, 'end_time' => $end]);
        $checkedIn = $make('08:00', '09:00');
        $missed = $make('09:00', '10:00');
        $pending = $make('14:00', '15:00');
        $attendee->breakouts()->attach([$checkedIn->id, $missed->id, $pending->id]);
        AttendanceRecord::factory()->create(['attendee_id' => $attendee->id, 'event_session_id' => null, 'breakout_id' => $checkedIn->id, 'check_in_at' => now()->subHours(3)]);

        $rows = collect($this->getBreakouts($user, $event)->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('checked_in', $rows[$checkedIn->id]['status']);
        $this->assertNotNull($rows[$checkedIn->id]['check_in_at']);
        $this->assertSame('missed', $rows[$missed->id]['status']);
        $this->assertNull($rows[$missed->id]['check_in_at']);
        $this->assertSame('pending', $rows[$pending->id]['status']);
    }

    public function test_breakout_with_attendance_off_has_no_status_or_check_in_time_and_restores_when_turned_on(): void
    {
        Carbon::setTestNow('2026-06-25 12:00:00');
        [$user, $attendee, $event] = $this->setupAttendee();
        $make = fn (string $start, string $end, array $extra = []) => Breakout::factory()->for($event)->create(['date' => '2026-06-25', 'start_time' => $start, 'end_time' => $end] + $extra);
        $missed = $make('09:00', '10:00', ['attendance_enabled' => false]);
        $pending = $make('14:00', '15:00', ['attendance_enabled' => false]);
        $checkedIn = $make('08:00', '09:00', ['attendance_enabled' => false]);
        $walkIn = $make('10:00', '11:00', ['attendance_enabled' => false]);
        $attendee->breakouts()->attach([$missed->id, $pending->id, $checkedIn->id]);
        AttendanceRecord::factory()->create(['attendee_id' => $attendee->id, 'event_session_id' => null, 'breakout_id' => $checkedIn->id, 'check_in_at' => now()->subHours(3)]);
        AttendanceRecord::factory()->create(['attendee_id' => $attendee->id, 'event_session_id' => null, 'breakout_id' => $walkIn->id, 'check_in_at' => now()->subHour(), 'is_walk_in' => true]);

        $rows = collect($this->getBreakouts($user, $event)->assertOk()->json('data'))->keyBy('id');
        $this->assertCount(4, $rows);
        foreach ([$missed, $pending, $checkedIn, $walkIn] as $breakout) {
            $this->assertNull($rows[$breakout->id]['status']);
            $this->assertNull($rows[$breakout->id]['check_in_at']);
            $this->assertFalse($rows[$breakout->id]['attendance_enabled']);
        }

        Breakout::whereKey([$missed->id, $pending->id, $checkedIn->id, $walkIn->id])->update(['attendance_enabled' => true]);

        $rows = collect($this->getBreakouts($user, $event)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('missed', $rows[$missed->id]['status']);
        $this->assertSame('pending', $rows[$pending->id]['status']);
        $this->assertSame('checked_in', $rows[$checkedIn->id]['status']);
        $this->assertNotNull($rows[$checkedIn->id]['check_in_at']);
        $this->assertSame('walk_in', $rows[$walkIn->id]['status']);
    }

    public function test_payload_never_exposes_capacity_pick_counts_or_overbooked(): void
    {
        [$user, $attendee, $event] = $this->setupAttendee(programPublic: true);
        $breakout = Breakout::factory()->for($event)->create(['capacity' => 1]);
        $attendee->breakouts()->attach($breakout->id, ['overbooked' => true]);
        Attendee::factory()->for($event->organization)->create(['event_id' => $event->id])->breakouts()->attach($breakout->id);

        $response = $this->getBreakouts($user, $event)->assertOk();

        $response->assertJsonMissingPath('data.0.capacity')
            ->assertJsonMissingPath('data.0.picks_count')
            ->assertJsonMissingPath('data.0.overbooked');
        $this->assertStringNotContainsString('overbooked', $response->getContent());
        $this->assertStringNotContainsString('capacity', $response->getContent());
        $this->assertStringNotContainsString('picks_count', $response->getContent());
    }

    public function test_draft_registration_returns_no_breakouts(): void
    {
        [$user, $attendee, $event] = $this->setupAttendee(programPublic: true, completed: false);
        $attendee->breakouts()->attach(Breakout::factory()->for($event)->create()->id);

        $this->getBreakouts($user, $event)->assertOk()->assertJsonPath('data', []);
    }

    public function test_event_without_pick_mode_returns_no_breakouts(): void
    {
        [$user, $attendee, $event] = $this->setupAttendee(pickMode: null, programPublic: true);
        $attendee->breakouts()->attach(Breakout::factory()->for($event)->create()->id);

        $this->getBreakouts($user, $event)->assertOk()->assertJsonPath('data', []);
    }

    public function test_unregistered_event_returns_not_found(): void
    {
        [$user] = $this->setupAttendee();
        $other = Event::factory()->for(Organization::factory())->create(['pick_mode' => 'multiple', 'pick_min' => 1]);

        $this->getBreakouts($user, $other)->assertNotFound();
    }

    public function test_non_attendee_user_is_forbidden(): void
    {
        [, , $event] = $this->setupAttendee();
        $admin = User::factory()->create(['organization_id' => $event->organization_id]);

        $this->getBreakouts($admin, $event)->assertForbidden();
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        [, , $event] = $this->setupAttendee();

        $this->getJson("/api/v1/attendee/events/{$event->id}/breakouts")->assertUnauthorized();
    }

    public function test_registrations_flag_has_breakouts_per_event(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->attendee()->create(['organization_id' => $organization->id]);
        $attendee = Attendee::factory()->for($organization)->for($user)->create();
        $make = function (?string $mode, bool $withBreakout) use ($organization, $attendee): Event {
            $event = Event::factory()->for($organization)->create(['pick_mode' => $mode, 'pick_min' => $mode ? 1 : null, 'pick_max' => null]);
            $this->registerInEvent($event, Attendee::query()->withoutGlobalScopes()->find($attendee->id));
            if ($withBreakout) {
                Breakout::factory()->for($event)->create();
            }

            return $event;
        };
        $on = $make('multiple', true);
        $onEmpty = $make('multiple', false);
        $offWith = $make(null, true);
        $offEmpty = $make(null, false);

        $rows = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/attendee/registrations')->assertOk()->json('data'))
            ->keyBy('event_id');

        $this->assertTrue($rows[$on->id]['event']['has_breakouts']);
        $this->assertFalse($rows[$onEmpty->id]['event']['has_breakouts']);
        $this->assertFalse($rows[$offWith->id]['event']['has_breakouts']);
        $this->assertFalse($rows[$offEmpty->id]['event']['has_breakouts']);
    }

    public function test_has_breakouts_does_not_depend_on_picks_or_program_visibility(): void
    {
        [$user, , $event] = $this->setupAttendee(programPublic: false);
        Breakout::factory()->for($event)->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendee/registrations')->assertOk()
            ->assertJsonPath('data.0.event.has_breakouts', true);
    }
}
