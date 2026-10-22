<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Exports\EventOverviewAttendanceSheetExport;
use App\Exports\EventSessionAttendanceSheetExport;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * One seeded event, every caller of the shared Present / Absent / Upcoming rule.
 */
class AttendanceStatusRuleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Event $event;

    private EventSession $ended;

    private EventSession $upcoming;

    private User $checker;

    /** @var array<string, Attendee> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $this->checker = User::factory()->checker()->for($org)->create();
        $this->event = Event::factory()->for($org)->create();
        $this->ended = EventSession::factory()->for($this->event)->create([
            'session_date' => today()->subDay()->toDateString(), 'start_time' => '09:00:00', 'end_time' => '11:00:00',
        ]);
        $this->upcoming = EventSession::factory()->for($this->event)->create([
            'session_date' => today()->addDay()->toDateString(), 'start_time' => '09:00:00', 'end_time' => '11:00:00',
        ]);

        $before = ['first_registered_at' => now()->subDays(3)];
        $after = ['first_registered_at' => now()->subHour()];
        foreach (['Present' => $before, 'Absent' => $before, 'Late' => $after, 'Walkin' => $after] as $name => $attributes) {
            $attendee = Attendee::factory()->for($org)->create(['first_name' => $name, 'last_name' => 'Test']);
            $this->people[$name] = $this->registerInEvent($this->event, $attendee, $attributes);
        }
        $draft = Attendee::factory()->for($org)->draft()->create(['first_name' => 'Draft', 'last_name' => 'Test']);
        $this->people['Draft'] = $this->registerInEvent($this->event, $draft, ['first_registered_at' => null]);

        foreach (['Present', 'Walkin'] as $name) {
            $this->people[$name]->attendanceRecords()->create([
                'event_session_id' => $this->ended->id, 'check_in_at' => now()->subDay(), 'method' => 'manual', 'recorded_by' => $this->checker->id,
            ]);
        }
    }

    private function names(array $rows, string $key = 'attendee_name'): array
    {
        return collect($rows)->mapWithKeys(fn ($row) => [explode(' ', $row[$key])[0] => $row['attendance_status']])->all();
    }

    public function test_model_rule(): void
    {
        $this->assertSame(AttendanceStatus::Present, $this->ended->attendanceStatus(true, null));
        $this->assertSame(AttendanceStatus::Absent, $this->ended->attendanceStatus(false, now()->subDays(3)));
        $this->assertNull($this->ended->attendanceStatus(false, now()->subHour()));
        $this->assertNull($this->ended->attendanceStatus(false, null));
        $this->assertSame(AttendanceStatus::Upcoming, $this->upcoming->attendanceStatus(false, now()));
        $this->assertNull($this->upcoming->attendanceStatus(false, null));
    }

    public function test_query_scopes_match_the_model_rule(): void
    {
        $firstNames = fn ($query) => $query->orderBy('first_name')->pluck('first_name')->all();

        $this->assertSame(['Absent', 'Present'], $firstNames($this->event->attendees()->expectedAt($this->ended)));
        $this->assertSame(['Absent', 'Late', 'Present', 'Walkin'], $firstNames($this->event->attendees()->expectedAt($this->upcoming)));
        $this->assertSame(['Absent'], $firstNames($this->event->attendees()->notCheckedInAt($this->ended)));
        $this->assertSame(['Absent', 'Present', 'Walkin'], $firstNames($this->event->attendees()->inAttendanceOf($this->ended)));
    }

    public function test_roster_lists_expected_attendees_and_walk_ins_only(): void
    {
        $url = fn (EventSession $s, string $q = '') => "/api/v1/events/{$this->event->id}/sessions/{$s->id}/roster{$q}";

        $ended = $this->actingAs($this->checker, 'sanctum')->getJson($url($this->ended))->assertOk();
        $this->assertSame(['Absent' => 'Absent', 'Present' => 'Present', 'Walkin' => 'Present'], collect($this->names($ended->json('data')))->sortKeys()->all());

        $upcoming = $this->actingAs($this->checker, 'sanctum')->getJson($url($this->upcoming))->assertOk();
        $this->assertSame(
            ['Absent' => 'Upcoming', 'Late' => 'Upcoming', 'Present' => 'Upcoming', 'Walkin' => 'Upcoming'],
            collect($this->names($upcoming->json('data')))->sortKeys()->all(),
        );

        $this->actingAs($this->checker, 'sanctum')->getJson($url($this->ended, '?status=not_checked_in'))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.attendance_status', 'Absent');
        $this->actingAs($this->checker, 'sanctum')->getJson($url($this->ended, '?status=absent'))->assertUnprocessable();
    }

    public function test_quick_stats_use_the_rule(): void
    {
        $this->actingAs($this->checker, 'sanctum')
            ->getJson("/api/v1/events/{$this->event->id}/sessions/{$this->ended->id}/quick-stats")
            ->assertJsonPath('total_registered', 2)
            ->assertJsonPath('attendance.present', 2)
            ->assertJsonPath('attendance.absent', 1)
            ->assertJsonPath('attendance.attendance_rate', 50);

        $this->actingAs($this->checker, 'sanctum')
            ->getJson("/api/v1/events/{$this->event->id}/sessions/{$this->upcoming->id}/quick-stats")
            ->assertJsonPath('attendance.absent', 0)
            ->assertJsonPath('attendance.upcoming', 4);
    }

    public function test_event_and_attendee_summaries_use_the_rule(): void
    {
        $sessions = collect($this->actingAs($this->checker, 'sanctum')
            ->getJson("/api/v1/events/{$this->event->id}/reports/attendance-summary")
            ->assertJsonPath('data.total_registered', 4)
            ->assertJsonPath('data.total_no_show', 1)
            ->json('data.sessions'))->keyBy('session_id');
        $this->assertSame(1, $sessions[$this->ended->id]['no_show']);
        $this->assertSame(0, $sessions[$this->upcoming->id]['no_show']);

        $rows = collect($this->actingAs($this->checker, 'sanctum')
            ->getJson("/api/v1/events/{$this->event->id}/reports/attendee-summary")
            ->assertOk()->json('data'))->keyBy('first_name');
        $this->assertCount(4, $rows);
        $this->assertSame(1, $rows['Absent']['absent']);
        $this->assertSame(1, $rows['Absent']['remaining']);
        $this->assertSame(0, $rows['Late']['absent']);
        $this->assertSame(0, $rows['Walkin']['absent']);
        $this->assertSame(100.0, (float) $rows['Present']['attendance_rating']);
    }

    public function test_attendee_dashboard_rows_use_the_rule(): void
    {
        foreach ([['Absent', 'absent', 'upcoming'], ['Late', null, 'upcoming'], ['Walkin', 'present', 'upcoming']] as [$name, $endedStatus, $upcomingStatus]) {
            $user = User::factory()->attendee()->create(['organization_id' => $this->event->organization_id]);
            $this->people[$name]->forceFill(['user_id' => $user->id])->save();

            $sessions = collect($this->actingAs($user, 'sanctum')
                ->getJson("/api/v1/attendee/events/{$this->event->id}/attendance")
                ->assertOk()->json('sessions'))->keyBy('id');

            $this->assertSame($endedStatus, $sessions[$this->ended->id]['status'], $name);
            $this->assertSame($upcomingStatus, $sessions[$this->upcoming->id]['status'], $name);
        }
    }

    public function test_exports_use_the_rule(): void
    {
        $session = (new EventSessionAttendanceSheetExport($this->event, $this->ended))->collection()->pluck('Status', 'First Name')->all();
        $this->assertSame(['Absent' => 'Absent', 'Present' => 'Checked In', 'Walkin' => 'Checked In'], collect($session)->sortKeys()->all());

        $upcoming = (new EventSessionAttendanceSheetExport($this->event, $this->upcoming))->collection()->pluck('Status', 'First Name')->all();
        $this->assertSame('Upcoming', $upcoming['Late']);
        $this->assertArrayNotHasKey('Draft', $upcoming);

        $overview = (new EventOverviewAttendanceSheetExport($this->event))->collection()->pluck('Status', 'First Name')->all();
        $this->assertSame(['Absent' => 'Absent', 'Late' => 'Upcoming', 'Present' => 'Checked In', 'Walkin' => 'Checked In'], collect($overview)->sortKeys()->all());
    }
}
